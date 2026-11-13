<?php

namespace Tests\Feature\Tenancy;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Matriz de roles por empresa: viewer solo lee, operator escribe pero no
 * elimina ni administra, admin hace todo dentro de su empresa.
 */
class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $operator;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        [$this->operator] = $this->memberOf($this->company, 'operator');
        [$this->viewer] = $this->memberOf($this->company, 'viewer');
    }

    public function test_viewer_reads_but_does_not_write(): void
    {
        $tender = Tender::factory()->for($this->company)->create(['title' => 'Original']);
        $opportunity = Opportunity::factory()->for($this->company)->create();
        $quote = Quote::factory()->for($this->company)->create();
        $contract = Contract::factory()->for($this->company)->create();

        foreach (['/', '/licitaciones', '/pipeline', '/cotizaciones', '/post-adjudicacion', '/documentos', '/clientes', '/reportes', '/kpis', '/calendario', '/inventario'] as $route) {
            $this->actingAs($this->viewer)->get($route)->assertOk();
        }

        $this->actingAs($this->viewer)->get("/pipeline/{$opportunity->id}")->assertOk();

        $writes = [
            ['post', '/licitaciones', ['reference' => 'X-1', 'title' => 'X', 'status' => 'Activa', 'estimated_amount' => 1]],
            ['put', "/licitaciones/{$tender->id}", ['reference' => 'X-1', 'title' => 'Cambiado', 'status' => 'Activa', 'estimated_amount' => 1]],
            ['delete', "/licitaciones/{$tender->id}", []],
            ['post', '/pipeline', ['title' => 'X', 'amount' => 1, 'probability' => 10, 'stage' => 'prospecto']],
            ['patch', "/pipeline/{$opportunity->id}/etapa", ['stage' => 'oportunidad']],
            ['delete', "/pipeline/{$opportunity->id}", []],
            ['post', '/cotizaciones', []],
            ['patch', "/cotizaciones/{$quote->id}/estado", ['status' => 'Emitida']],
            ['patch', "/cotizaciones/{$quote->id}/resultado", ['outcome' => 'Ganada']],
            ['delete', "/cotizaciones/{$quote->id}", []],
            ['post', '/post-adjudicacion', ['number' => 'C-1', 'name' => 'X', 'amount' => 1]],
            ['delete', "/post-adjudicacion/{$contract->id}", []],
            ['post', '/documentos', ['name' => 'X']],
            ['post', '/calendario', ['title' => 'Reunión']],
            ['post', '/kpis', ['metric' => 'cobros', 'target' => 1, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31']],
            ['put', '/configuracion', ['settings' => ['address' => 'X']]],
        ];

        foreach ($writes as [$method, $uri, $payload]) {
            $response = $this->actingAs($this->viewer)->{$method}($uri, $payload);
            $this->assertSame(403, $response->getStatusCode(), strtoupper($method)." {$uri} respondió {$response->getStatusCode()}");
        }

        $this->assertDatabaseHas('tenders', ['id' => $tender->id, 'title' => 'Original']);
        $this->assertDatabaseCount('tenders', 1);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_viewer_has_no_ai_and_no_administration(): void
    {
        foreach (['/agente-legal', '/ia-licitaciones', '/generador-documentos', '/calculadora-oferta', '/configuracion', '/configuracion/usuarios', '/configuracion/diagnostico'] as $route) {
            $this->actingAs($this->viewer)->get($route)->assertForbidden();
        }

        $this->actingAs($this->viewer)->post('/agente-legal/chat', ['message' => 'Hola'])->assertForbidden();
    }

    public function test_operator_writes_but_does_not_delete_or_administer(): void
    {
        $tender = Tender::factory()->for($this->company)->create();
        $payload = ['reference' => $tender->reference, 'title' => 'Editada', 'status' => 'Activa', 'estimated_amount' => 10, 'offer_submitted' => false];

        $this->actingAs($this->operator)->put("/licitaciones/{$tender->id}", $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('tenders', ['id' => $tender->id, 'title' => 'Editada']);

        $this->actingAs($this->operator)->post('/calendario', ['title' => 'Reunión', 'starts_at' => now()->addDay()->toDateTimeString()])->assertRedirect();
        $this->assertDatabaseHas('events', ['title' => 'Reunión', 'company_id' => $this->company->id, 'user_id' => $this->operator->id]);

        $this->actingAs($this->operator)->delete("/licitaciones/{$tender->id}")->assertForbidden();
        $this->actingAs($this->operator)->get('/configuracion')->assertForbidden();
        $this->actingAs($this->operator)->post("/empresas/{$this->company->id}/miembros", ['user_id' => $this->viewer->id, 'role' => 'admin'])->assertForbidden();

        $this->actingAs($this->admin)->delete("/licitaciones/{$tender->id}")->assertRedirect();
        $this->assertDatabaseMissing('tenders', ['id' => $tender->id]);
    }

    public function test_the_role_is_per_company(): void
    {
        // Administrador en una empresa, solo lectura en otra.
        $other = Company::factory()->create();
        $other->users()->attach($this->admin->id, ['role' => 'viewer', 'is_default' => false]);
        $tender = Tender::factory()->for($other)->create();

        $this->actingInCompany($this->admin, $other)->get('/licitaciones')->assertOk();
        $this->actingInCompany($this->admin, $other)->delete("/licitaciones/{$tender->id}")->assertForbidden();
        $this->actingInCompany($this->admin, $other)->get('/configuracion')->assertForbidden();

        // Estando en su empresa no puede crear en la otra, donde solo lee.
        $this->actingInCompany($this->admin, $this->company)
            ->post('/pipeline', ['company_id' => $other->id, 'title' => 'X', 'amount' => 1, 'probability' => 10, 'stage' => 'prospecto'])
            ->assertSessionHasErrors('company_id');
    }

    public function test_company_admin_manages_members_and_roles_of_its_company(): void
    {
        $newcomer = User::factory()->create();

        $this->actingAs($this->admin)->post("/empresas/{$this->company->id}/miembros", ['user_id' => $newcomer->id, 'role' => 'viewer'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('company_user', ['company_id' => $this->company->id, 'user_id' => $newcomer->id, 'role' => 'viewer']);

        $this->actingAs($this->admin)->put("/configuracion/usuarios/{$newcomer->id}/rol", ['role' => 'operator'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('company_user', ['user_id' => $newcomer->id, 'role' => 'operator']);

        $this->actingAs($this->admin)->put("/configuracion/usuarios/{$newcomer->id}/rol", ['role' => 'dueño'])->assertSessionHasErrors('role');
        $this->actingAs($this->admin)->put("/configuracion/usuarios/{$this->admin->id}/rol", ['role' => 'viewer'])->assertSessionHasErrors('role');
        $this->assertDatabaseHas('company_user', ['user_id' => $this->admin->id, 'role' => 'admin']);
    }

    public function test_settings_belong_to_the_active_company(): void
    {
        [$otherAdmin, $other] = $this->memberOf();

        $this->actingAs($this->admin)->put('/configuracion', ['settings' => ['address' => 'Col. Palmira']])->assertRedirect();

        $this->assertSame('Col. Palmira', $this->company->fresh()->settings['address']);
        $this->assertNull($other->fresh()->settings);

        $this->actingAs($otherAdmin)->get('/configuracion')->assertInertia(fn (Assert $page) => $page
            ->where('settings.name', $other->name)
            ->missing('settings.address'));
    }

    public function test_shared_abilities_follow_the_matrix(): void
    {
        $expect = fn (User $user, array $abilities) => $this->actingAs($user)->get('/')->assertInertia(
            fn (Assert $page) => $page->where('auth.can', fn ($can) => collect($can)->only(array_keys($abilities))->all() == $abilities)
        );

        $expect($this->viewer, ['create' => false, 'update' => false, 'delete' => false, 'use_ai' => false, 'view_reports' => true, 'manage_users' => false, 'view_diagnostics' => false]);
        $expect($this->operator, ['create' => true, 'update' => true, 'delete' => false, 'use_ai' => true, 'manage_settings' => false, 'view_audit' => false]);
        $expect($this->admin, ['create' => true, 'delete' => true, 'manage_users' => true, 'manage_settings' => true, 'view_audit' => true, 'view_diagnostics' => true, 'manage_companies' => false]);

        $this->assertSame([], Permissions::MATRIX[Permissions::MANAGE_COMPANIES]);
        $this->assertNotContains(Permissions::DELETE, Permissions::forRole(Permissions::ROLE_OPERATOR));
        $this->assertSame([Permissions::VIEW, Permissions::VIEW_REPORTS], Permissions::forRole(Permissions::ROLE_VIEWER));
    }
}
