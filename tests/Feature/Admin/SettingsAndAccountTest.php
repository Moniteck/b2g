<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Services\System\SystemDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsAndAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_contract_and_update_of_the_active_company(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');
        $other = Company::factory()->create(['tax_id' => '08011111111111']);

        $this->actingAs($admin)->get('/configuracion')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings')
            ->where('company.id', $company->id)
            ->where('settings.name', $company->name)
            ->missing('company.blessing_token'));

        $this->actingAs($admin)->put('/configuracion', ['name' => 'CAP Renombrada', 'settings' => ['address' => 'Col. Palmira', 'quote_footer' => 'Gracias', 'logo' => 'hack.png']])
            ->assertSessionHasNoErrors();
        $fresh = $company->fresh();
        $this->assertSame('CAP Renombrada', $fresh->name);
        $this->assertSame('Col. Palmira', $fresh->settings['address']);
        $this->assertArrayNotHasKey('logo', $fresh->settings);

        $this->actingAs($admin)->put('/configuracion', ['tax_id' => '08011111111111'])->assertSessionHasErrors('tax_id');
        $this->actingAs($viewer)->put('/configuracion', ['name' => 'X'])->assertForbidden();
        $this->assertNull($other->fresh()->settings);
    }

    public function test_logo_preview_and_strong_reset(): void
    {
        Storage::fake('local');
        [$admin, $company] = $this->memberOf();

        $this->actingAs($admin)->post('/configuracion/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 100)])->assertSessionHasNoErrors();
        $path = $company->fresh()->logo_path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($admin)->put('/configuracion', ['settings' => ['address' => 'Barrio El Centro']]);
        $this->actingAs($admin)->get('/configuracion/preview.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($admin)->post('/configuracion/reset', ['confirmation' => 'otra cosa'])->assertSessionHasErrors('confirmation');
        $this->assertNotNull($company->fresh()->logo_path);

        $this->actingAs($admin)->post('/configuracion/reset', ['confirmation' => $company->name])->assertSessionHasNoErrors();
        $this->assertNull($company->fresh()->logo_path);
        $this->assertNull($company->fresh()->settings);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'configuracion.restablecida', 'company_id' => $company->id]);
    }

    public function test_account_updates_name_and_password(): void
    {
        [$user] = $this->memberOf(role: 'viewer', attributes: ['password' => 'ClaveVieja123']);

        $this->actingAs($user)->get('/mi-cuenta')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Account')->where('account.email', $user->email)->has('memberships', 1));

        $this->actingAs($user)->put('/mi-cuenta', ['name' => 'Nombre Nuevo', 'phone' => '9999-0000'])->assertSessionHasNoErrors();
        $this->assertSame('Nombre Nuevo', $user->fresh()->name);

        $this->actingAs($user)->put('/mi-cuenta/contrasena', ['current_password' => 'incorrecta', 'password' => 'ClaveNueva456', 'password_confirmation' => 'ClaveNueva456'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/mi-cuenta/contrasena', ['current_password' => 'ClaveVieja123', 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertSessionHasErrors('password');
        $this->actingAs($user)->put('/mi-cuenta/contrasena', ['current_password' => 'ClaveVieja123', 'password' => 'ClaveNueva456', 'password_confirmation' => 'ClaveNueva456'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('ClaveNueva456', $user->fresh()->password));
    }

    public function test_diagnostics_page_includes_the_https_check(): void
    {
        [$admin] = $this->memberOf();

        $this->actingAs($admin)->get('/configuracion/diagnostico')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Diagnostics')
            ->where('checks', fn ($checks) => collect($checks)->contains('key', 'https'))
            ->has('summary')
            ->has('generated_at'));

        $check = app(SystemDiagnostics::class)->httpsOutbound();
        $this->assertContains($check['status'], ['ok', 'warning', 'error']);
        $this->assertSame('https', $check['key']);
    }
}
