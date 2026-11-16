<?php

namespace Tests\Feature\Shell;

use App\Models\Company;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Marco: búsqueda global, modo soporte, rutas solo JSON abiertas en el navegador, páginas de error
 * y la prop de Empresas que ya no pisa la del selector (B-12).
 */
class ShellTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = ['Accept' => 'text/html,application/xhtml+xml', 'Sec-Fetch-Mode' => 'navigate'];

    public function test_search_finds_records_of_the_active_company_only(): void
    {
        [$user, $company] = $this->memberOf(null, 'viewer');
        $other = Company::factory()->create();
        $this->inCompany($company, fn () => Tender::factory()->for($company)->create(['reference' => 'LPN-BUSCAR-001', 'title' => 'Compra de llantas']));
        $this->inCompany($other, fn () => Tender::factory()->for($other)->create(['reference' => 'LPN-BUSCAR-999', 'title' => 'Compra ajena']));

        $response = $this->actingInCompany($user, $company)->getJson('/buscar?q=BUSCAR')->assertOk();

        $tenders = collect($response->json('groups'))->firstWhere('type', 'tenders');
        $this->assertNotNull($tenders);
        $this->assertSame(['LPN-BUSCAR-001'], array_column($tenders['items'], 'title'));
        $this->assertStringStartsWith('/licitaciones/', $tenders['items'][0]['href']);
    }

    public function test_search_needs_two_letters_and_a_session(): void
    {
        [$user, $company] = $this->memberOf();

        $this->actingInCompany($user, $company)->getJson('/buscar?q=a')->assertOk()->assertJson(['groups' => []]);
        auth()->logout();
        $this->getJson('/buscar?q=abc')->assertUnauthorized();
    }

    public function test_root_enters_support_mode_without_membership_and_it_is_audited(): void
    {
        [$root, $home] = $this->memberOf(null, 'admin', ['is_root' => true]);
        $foreign = Company::factory()->create(['name' => 'Empresa Ajena']);

        $this->actingInCompany($root, $home)->getJson('/soporte/estado')->assertOk()->assertJson(['support' => false, 'member_ids' => [$home->id]]);

        $this->actingInCompany($root, $home)->post('/soporte/entrar', ['company_id' => $foreign->id])
            ->assertRedirect()->assertSessionHas(CompanyContext::SESSION_KEY, $foreign->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'soporte.entrada', 'company_id' => $foreign->id, 'user_id' => $root->id]);
        $this->assertDatabaseMissing('company_user', ['company_id' => $foreign->id, 'user_id' => $root->id]);

        $this->actingInCompany($root, $foreign)->getJson('/soporte/estado')->assertJson(['support' => true, 'company' => ['id' => $foreign->id]]);

        $this->actingInCompany($root, $foreign)->post('/soporte/salir')->assertSessionHas(CompanyContext::SESSION_KEY, $home->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'soporte.salida', 'company_id' => $foreign->id]);
    }

    public function test_only_root_can_enter_support_mode(): void
    {
        [$admin, $company] = $this->memberOf();
        $foreign = Company::factory()->create();

        $this->actingInCompany($admin, $company)->post('/soporte/entrar', ['company_id' => $foreign->id])->assertForbidden();
        $this->actingInCompany($admin, $company)->getJson('/soporte/estado')->assertJson(['support' => false, 'member_ids' => null]);
    }

    public function test_json_only_routes_redirect_browser_navigation_to_their_screen(): void
    {
        [$user, $company] = $this->memberOf();
        $quote = $this->inCompany($company, fn () => Quote::factory()->for($company)->create());

        $this->actingInCompany($user, $company)->get("/cotizaciones/{$quote->id}", self::BROWSER)->assertRedirect("/cotizaciones?ver={$quote->id}");
        $this->actingInCompany($user, $company)->get('/post-adjudicacion/feriados', self::BROWSER)->assertRedirect('/post-adjudicacion?panel=feriados');
        $this->actingInCompany($user, $company)->get('/inventario/sincronizaciones', self::BROWSER)->assertRedirect('/inventario?panel=sincronizaciones');

        // La propia app (fetch con Accept JSON) sigue recibiendo JSON.
        $this->actingInCompany($user, $company)->getJson("/cotizaciones/{$quote->id}")->assertOk()->assertJsonPath('data.id', $quote->id);
    }

    public function test_error_pages_render_in_spanish_with_inertia_and_json_is_unchanged(): void
    {
        [$user, $company] = $this->memberOf();

        $this->actingInCompany($user, $company)->get('/licitaciones/999999', self::BROWSER)
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('Errors/Show')->where('status', 404));

        $this->actingInCompany($user, $company)->getJson('/licitaciones/999999')->assertNotFound()->assertJsonMissingPath('component');

        [$viewer] = $this->memberOf($company, 'viewer');
        $this->actingInCompany($viewer, $company)->get('/configuracion', self::BROWSER)
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Errors/Show')->where('status', 403));
    }

    public function test_inertia_form_errors_go_back_with_a_toast(): void
    {
        [$viewer, $company] = $this->memberOf(null, 'viewer');

        $this->actingInCompany($viewer, $company)
            ->from('/configuracion/usuarios')
            ->post('/configuracion/usuarios', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'], ['X-Inertia' => 'true'])
            ->assertRedirect('/configuracion/usuarios')
            ->assertSessionHas('error');
    }

    public function test_companies_page_does_not_override_the_shared_switcher_prop(): void
    {
        [$admin, $company] = $this->memberOf();

        $this->actingInCompany($admin, $company)->get('/empresas')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Companies/Index')
            ->has('companyRecords.data', 1)
            ->has('companies', 1)
            ->where('companies.0.id', $company->id));
    }

    public function test_admin_index_page_renders(): void
    {
        [$user, $company] = $this->memberOf(null, 'viewer');

        $this->actingInCompany($user, $company)->get('/administracion')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Index'));
    }
}
