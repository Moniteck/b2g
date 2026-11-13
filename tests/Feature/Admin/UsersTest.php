<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_reach_the_users_screen(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');

        $this->actingAs($admin)->get('/configuracion/usuarios')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users')
            ->has('users.data', 3)
            ->where('counts.all', 3)
            ->where('counts.admin', 1)
            ->where('isRoot', false)
            ->where('users.data.0.companies', null));

        foreach ([$operator, $viewer] as $user) {
            $this->actingAs($user)->get('/configuracion/usuarios')->assertForbidden();
            $this->actingAs($user)->post('/configuracion/usuarios', ['name' => 'X', 'email' => 'x@x.hn', 'role' => 'viewer'])->assertForbidden();
        }
    }

    public function test_admin_creates_a_user_and_sees_the_temporary_password_once(): void
    {
        [$admin, $company] = $this->memberOf();

        $this->actingAs($admin)->post('/configuracion/usuarios', ['name' => 'Rosa Díaz', 'email' => 'Rosa@Empresa.hn', 'role' => 'operator'])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('temporary_credentials');

        $user = User::query()->where('email', 'rosa@empresa.hn')->firstOrFail();
        $this->assertSame('operator', $user->companyRole($company->id));

        $credentials = session('temporary_credentials');
        $this->assertTrue(Hash::check($credentials['password'], $user->password));

        $this->actingAs($admin)->get('/configuracion/usuarios')->assertInertia(fn (Assert $page) => $page->where('credentials.email', 'rosa@empresa.hn'));
        $this->actingAs($admin)->get('/configuracion/usuarios')->assertInertia(fn (Assert $page) => $page->where('credentials', null));

        $this->assertDatabaseHas('audit_logs', ['action' => 'usuario.creado', 'company_id' => $company->id]);
        $this->assertDatabaseMissing('audit_logs', ['changes' => json_encode(['password' => $credentials['password']])]);
    }

    public function test_existing_account_is_added_without_touching_its_password(): void
    {
        [$admin, $company] = $this->memberOf();
        [$elsewhere] = $this->memberOf(attributes: ['email' => 'ya@existe.hn', 'password' => 'ClaveOriginal123']);

        $this->actingAs($admin)->post('/configuracion/usuarios', ['name' => 'Otro nombre', 'email' => 'ya@existe.hn', 'role' => 'viewer'])
            ->assertSessionHasNoErrors()->assertSessionMissing('temporary_credentials');

        $this->assertTrue(Hash::check('ClaveOriginal123', $elsewhere->fresh()->password));
        $this->assertSame('viewer', $elsewhere->fresh()->companyRole($company->id));

        $this->actingAs($admin)->post('/configuracion/usuarios', ['name' => 'X', 'email' => 'ya@existe.hn', 'role' => 'viewer'])->assertSessionHasErrors('email');
        $this->actingAs($admin)->post('/configuracion/usuarios', ['name' => 'X', 'email' => 'malo', 'role' => 'jefe'])->assertSessionHasErrors(['email', 'role']);
    }

    public function test_role_changes_and_the_last_admin_rule(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');

        $this->actingAs($admin)->put("/configuracion/usuarios/{$admin->id}/rol", ['role' => 'viewer'])->assertSessionHasErrors('role');
        $this->actingAs($admin)->delete("/configuracion/usuarios/{$admin->id}")->assertSessionHas('error');

        $this->actingAs($admin)->put("/configuracion/usuarios/{$operator->id}/rol", ['role' => 'admin'])->assertSessionHasNoErrors();
        $this->assertSame('admin', $operator->fresh()->companyRole($company->id));

        // Con dos administradores ya puede bajarse a sí mismo.
        $this->actingAs($admin)->put("/configuracion/usuarios/{$admin->id}/rol", ['role' => 'operator'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'usuario.rol_cambiado', 'resource_id' => $admin->id]);
    }

    public function test_admin_removes_a_member_but_not_users_of_other_companies(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');
        [$stranger] = $this->memberOf();

        $this->actingAs($admin)->delete("/configuracion/usuarios/{$viewer->id}")->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseMissing('company_user', ['company_id' => $company->id, 'user_id' => $viewer->id]);
        $this->assertDatabaseHas('users', ['id' => $viewer->id]);

        $this->actingAs($admin)->delete("/configuracion/usuarios/{$stranger->id}")->assertNotFound();
        $this->actingAs($admin)->put("/configuracion/usuarios/{$stranger->id}/rol", ['role' => 'viewer'])->assertNotFound();
        $this->actingAs($admin)->post("/configuracion/usuarios/{$stranger->id}/contrasena")->assertNotFound();
    }

    public function test_password_reset_is_limited_to_accounts_the_admin_fully_controls(): void
    {
        [$admin, $company] = $this->memberOf();
        [$own] = $this->memberOf($company, 'operator');
        [$shared, $other] = $this->memberOf();
        $company->users()->attach($shared->id, ['role' => 'viewer', 'is_default' => false]);
        $root = User::factory()->root()->create();
        $company->users()->attach($root->id, ['role' => 'admin', 'is_default' => true]);

        $this->actingAs($admin)->post("/configuracion/usuarios/{$own->id}/contrasena")->assertSessionHas('temporary_credentials');
        $this->assertTrue(Hash::check(session('temporary_credentials')['password'], $own->fresh()->password));

        $before = $shared->password;
        $this->actingAs($admin)->post("/configuracion/usuarios/{$shared->id}/contrasena")->assertSessionHas('error');
        $this->assertSame($before, $shared->fresh()->password);

        $this->actingAs($admin)->post("/configuracion/usuarios/{$root->id}/contrasena")->assertSessionHas('error');

        // La raíz sí puede, y ve las empresas de cada usuario.
        $this->actingInCompany($root, $company)->post("/configuracion/usuarios/{$shared->id}/contrasena")->assertSessionHas('temporary_credentials');
        $this->actingInCompany($root, $company)->get('/configuracion/usuarios')->assertInertia(fn (Assert $page) => $page
            ->where('isRoot', true)
            ->has('users.data.0.companies'));
        $this->assertNotNull($other);
    }

    public function test_users_screen_is_isolated_by_active_company(): void
    {
        [$admin, $company] = $this->memberOf();
        $other = Company::factory()->create();
        [$foreign] = $this->memberOf($other, 'operator');

        $this->actingAs($admin)->get('/configuracion/usuarios')->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)->where('users.data.0.id', $admin->id));
        $this->actingInCompany($admin, $other)->get('/configuracion/usuarios')->assertInertia(fn (Assert $page) => $page
            ->where('company.id', $company->id));
        $this->assertNotNull($foreign);
    }
}
