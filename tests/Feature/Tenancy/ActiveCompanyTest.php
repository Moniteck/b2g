<?php

namespace Tests\Feature\Tenancy;

use App\Models\Company;
use App\Models\Tender;
use App\Models\User;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActiveCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_switches_the_active_company_and_lists_follow_it(): void
    {
        [$user, $companyA] = $this->memberOf(Company::factory()->create(['name' => 'Alfa']));
        $companyB = Company::factory()->create(['name' => 'Beta']);
        $companyB->users()->attach($user->id, ['role' => 'operator', 'is_default' => false]);

        Tender::factory()->for($companyA)->create(['reference' => 'LPN-ALFA']);
        Tender::factory()->for($companyB)->create(['reference' => 'LPN-BETA']);

        $this->actingAs($user)->get('/licitaciones')->assertSee('LPN-ALFA')->assertDontSee('LPN-BETA');

        $this->actingAs($user)->from('/licitaciones')->post('/empresa-activa', ['company_id' => $companyB->id])
            ->assertRedirect('/licitaciones')
            ->assertSessionHas(CompanyContext::SESSION_KEY, $companyB->id)
            ->assertSessionHas('success');

        $this->actingAs($user)->get('/licitaciones')
            ->assertSee('LPN-BETA')->assertDontSee('LPN-ALFA')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeCompany', ['id' => $companyB->id, 'name' => 'Beta'])
                ->where('auth.user.company_role', 'operator')
                ->has('companies', 2));
    }

    public function test_user_cannot_switch_to_a_company_without_access(): void
    {
        [$user, $company] = $this->memberOf();
        $foreign = Company::factory()->create();

        $this->actingAs($user)->post('/empresa-activa', ['company_id' => $foreign->id])
            ->assertSessionHasErrors('company_id');
        $this->actingAs($user)->post('/empresa-activa', [])->assertSessionHasErrors('company_id');

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->where('activeCompany.id', $company->id));
    }

    public function test_a_stale_session_company_falls_back_to_an_allowed_one(): void
    {
        [$user, $company] = $this->memberOf();
        $foreign = Company::factory()->create();

        $this->actingInCompany($user, $foreign)->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('activeCompany.id', $company->id));
    }

    public function test_root_reaches_every_company_and_is_not_a_company_role(): void
    {
        $root = User::factory()->root()->create();
        $companyA = Company::factory()->create(['name' => 'Alfa']);
        $companyB = Company::factory()->create(['name' => 'Beta']);
        Tender::factory()->for($companyB)->create(['reference' => 'LPN-BETA']);

        $this->actingAs($root)->post('/empresa-activa', ['company_id' => $companyB->id])->assertSessionHasNoErrors();
        $this->actingAs($root)->get('/licitaciones')->assertSee('LPN-BETA')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.is_root', true)
                ->where('auth.can.manage_companies', true)
                ->has('companies', 2));

        // Un administrador de empresa NO es raíz: no ve otras empresas ni las administra.
        [$admin] = $this->memberOf($companyA, 'admin');

        $this->assertFalse($admin->isRoot());
        $this->actingAs($admin)->post('/empresa-activa', ['company_id' => $companyB->id])->assertSessionHasErrors('company_id');
        $this->actingAs($admin)->post('/empresas', ['name' => 'Nueva'])->assertForbidden();
        $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.is_root', false)
            ->where('auth.can.manage_companies', false)
            ->has('companies', 1));
    }
}
