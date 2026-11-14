<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Company;
use App\Models\Document;
use App\Models\Institution;
use App\Models\Tender;
use App\Models\User;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use App\Services\System\SystemDiagnostics;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Diagnóstico, comando de raíz, props compartidas, reglas reutilizables e idioma.
 */
class SystemFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_reports_every_check_to_company_admins(): void
    {
        Process::fake([
            '*--list-langs*' => Process::result("List of available languages (2):\neng\nspa\n"),
            '*tesseract*' => Process::result('tesseract v5.3.0'),
            '*pdftoppm*' => Process::result('', '', 127),
            '*' => Process::result('', 'pdftotext version 24.02.0'),
        ]);
        config(['services.openai.key' => null, 'mail.default' => 'log']);
        SystemDiagnostics::recordSchedulerHeartbeat();

        [$admin] = $this->memberOf();

        $this->actingAs($admin)->get('/configuracion/diagnostico')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Diagnostics', false)
            ->has('checks', 8)
            ->has('generated_at')
            ->where('checks.0.key', 'tesseract')
            ->where('checks.0.status', 'ok')
            ->where('checks.0.meta.has_language', true)
            ->where('checks.1.key', 'poppler')
            ->where('checks.1.status', 'error')
            ->where('checks.1.meta.binaries.pdftoppm.installed', false)
            ->where('checks.1.meta.binaries.pdftotext.installed', true)
            ->where('checks.2.key', 'php_limits')
            ->has('checks.2.meta.limits.upload_max_filesize.required')
            ->where('checks.3.key', 'queue')
            ->where('checks.3.meta.failed', 0)
            ->where('checks.4.key', 'scheduler')
            ->where('checks.4.status', 'ok')
            ->where('checks.5.key', 'openai')
            ->where('checks.5.status', 'warning')
            ->where('checks.6.key', 'mailer')
            ->where('checks.6.status', 'warning')
            ->has('summary.ok')->has('summary.warning')->has('summary.error'));
    }

    public function test_diagnostics_is_for_root_and_company_admins_only(): void
    {
        Process::fake();

        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        Company::factory()->create();

        $this->get('/configuracion/diagnostico')->assertRedirect(route('login'));
        $this->actingAs($operator)->get('/configuracion/diagnostico')->assertForbidden();
        $this->actingAs($admin)->get('/configuracion/diagnostico')->assertOk();
        $this->actingAs(User::factory()->root()->create())->get('/configuracion/diagnostico')->assertOk();
    }

    public function test_diagnostics_detects_missing_tools_stopped_scheduler_and_low_limits(): void
    {
        Process::fake(['*' => Process::result('', 'no encontrado', 1)]);
        Cache::forget(SystemDiagnostics::SCHEDULER_HEARTBEAT);
        config(['tools.uploads.max_pdf_mb' => 999999]);

        $diagnostics = app(SystemDiagnostics::class);

        $this->assertSame('error', $diagnostics->tesseract()['status']);
        $this->assertFalse($diagnostics->tesseract()['meta']['installed']);
        $this->assertSame('error', $diagnostics->poppler()['status']);
        $this->assertSame('error', $diagnostics->scheduler()['status']);
        $this->assertSame('error', $diagnostics->phpLimits()['status']);

        Cache::forever(SystemDiagnostics::SCHEDULER_HEARTBEAT, now()->subMinutes(30)->toIso8601String());
        $this->assertSame('error', $diagnostics->scheduler()['status']);

        $this->assertSame(40.0, SystemDiagnostics::megabytes('40M'));
        $this->assertSame(2048.0, SystemDiagnostics::megabytes('2G'));
        $this->assertSame(-1.0, SystemDiagnostics::megabytes('-1'));
    }

    public function test_tesseract_without_spanish_is_an_error(): void
    {
        Process::fake([
            '*--list-langs*' => Process::result("List of available languages (1):\neng\n"),
            '*' => Process::result('tesseract v5.3.0'),
        ]);

        $check = app(SystemDiagnostics::class)->tesseract();

        $this->assertSame('error', $check['status']);
        $this->assertTrue($check['meta']['installed']);
        $this->assertFalse($check['meta']['has_language']);
    }

    public function test_crm_root_command_marks_and_revokes_the_root_user(): void
    {
        $user = User::factory()->create(['email' => 'persona@crm-b2g.test']);

        $this->artisan('crm:root', ['email' => 'nadie@crm-b2g.test'])->assertFailed();
        $this->artisan('crm:root', ['email' => 'Persona@CRM-B2G.test'])->assertSuccessful();
        $this->assertTrue($user->fresh()->isRoot());

        // No se puede dejar el sistema sin raíz.
        $this->artisan('crm:root', ['email' => 'persona@crm-b2g.test', '--revoke' => true])->assertFailed();
        $this->assertTrue($user->fresh()->isRoot());

        User::factory()->root()->create();
        $this->artisan('crm:root', ['email' => 'persona@crm-b2g.test', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->isRoot());
    }

    public function test_the_legacy_admin_role_column_grants_nothing(): void
    {
        $legacyAdmin = User::factory()->create(['role' => 'admin']);

        $this->assertFalse($legacyAdmin->isRoot());
        $this->actingAs($legacyAdmin)->get('/licitaciones')->assertForbidden();
        $this->actingAs($legacyAdmin)->get('/empresas')->assertOk()->assertInertia(fn (Assert $page) => $page->has('records', 0));
    }

    public function test_shared_props_follow_the_frontend_contract(): void
    {
        [$user, $company] = $this->memberOf(Company::factory()->create(['name' => 'CAP Honduras']), 'operator', ['name' => 'María José López']);

        $this->actingAs($user)->withSession(['success' => 'Guardado.', 'error' => 'Falló.', 'warning' => 'Cuidado.'])->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user', [
                    'id' => $user->id,
                    'name' => 'María José López',
                    'email' => $user->email,
                    'initials' => 'MJ',
                    'is_root' => false,
                    'company_role' => 'operator',
                ])
                ->has('auth.can.manage_users')
                ->has('auth.can.manage_companies')
                ->has('auth.can.manage_settings')
                ->has('auth.can.use_ai')
                ->has('auth.can.view_audit')
                ->has('auth.can.view_diagnostics')
                ->where('companies', [['id' => $company->id, 'name' => 'CAP Honduras']])
                ->where('activeCompany', ['id' => $company->id, 'name' => 'CAP Honduras'])
                ->where('flash', ['success' => 'Guardado.', 'error' => 'Falló.', 'warning' => 'Cuidado.'])
                ->where('notifications', ['unread' => 0, 'items' => []])
                ->where('topbar.alertsCount', 0));
    }

    public function test_alerts_count_is_cached_per_user_and_company(): void
    {
        [$user, $company] = $this->memberOf();
        Document::factory()->for($company)->expired()->create();

        $this->actingAs($user)->get('/licitaciones')->assertInertia(fn (Assert $page) => $page->where('topbar.alertsCount', 1));

        // Un cambio posterior no se refleja hasta que vence la caché de 60 s.
        Document::factory()->for($company)->expired()->create();
        $this->actingAs($user)->get('/licitaciones')->assertInertia(fn (Assert $page) => $page->where('topbar.alertsCount', 1));

        $this->assertSame(1, Cache::get(HandleInertiaRequests::alertsCacheKey($user->id, $company->id)));

        $this->travel(HandleInertiaRequests::ALERTS_TTL + 1)->seconds();
        $this->actingAs($user)->get('/licitaciones')->assertInertia(fn (Assert $page) => $page->where('topbar.alertsCount', 2));
    }

    public function test_reusable_rules_validate_company_and_related_ids(): void
    {
        [$user, $company] = $this->memberOf();
        $foreign = Company::factory()->create();
        [$foreignUser] = $this->memberOf($foreign);
        $ownTender = Tender::factory()->for($company)->create();
        $foreignTender = Tender::factory()->for($foreign)->create();
        $institution = Institution::factory()->create();

        app(CompanyContext::class)->initialize($user, [$company->id], $company->id);

        $passes = fn (mixed $value, object $rule, array $extra = []) => Validator::make(['field' => $value] + $extra, ['field' => [$rule]])->passes();

        $this->assertTrue($passes($company->id, new AllowedCompany));
        $this->assertFalse($passes($foreign->id, new AllowedCompany));
        $this->assertFalse($passes('abc', new AllowedCompany));

        $this->assertTrue($passes($ownTender->id, new BelongsToActiveCompany(Tender::class)));
        $this->assertFalse($passes($foreignTender->id, new BelongsToActiveCompany(Tender::class)));
        $this->assertFalse($passes(999999, new BelongsToActiveCompany(Tender::class)));
        // Un `company_id` ajeno en la petición no sirve para alcanzar sus registros.
        $this->assertFalse($passes($foreignTender->id, new BelongsToActiveCompany(Tender::class), ['company_id' => $foreign->id]));

        // Catálogo compartido: basta con que exista. Usuarios: deben ser miembros.
        $this->assertTrue($passes($institution->id, new BelongsToActiveCompany(Institution::class)));
        $this->assertTrue($passes($user->id, new BelongsToActiveCompany(User::class)));
        $this->assertFalse($passes($foreignUser->id, new BelongsToActiveCompany(User::class)));

        app(CompanyContext::class)->forget();
    }

    public function test_validation_messages_are_in_spanish(): void
    {
        $this->assertSame('es', app()->getLocale());

        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);

        $message = session('errors')->first('email');
        $this->assertStringContainsString('obligatorio', $message);
        $this->assertStringContainsString('correo electrónico', $message);
    }

    public function test_models_take_the_active_company_on_create_and_scope_to_it(): void
    {
        [$user, $company] = $this->memberOf();
        $other = Company::factory()->create();
        Tender::factory()->for($other)->create();

        app(CompanyContext::class)->initialize($user, [$company->id], $company->id);

        $tender = Tender::create(['reference' => 'LPN-1', 'title' => 'Sin empresa explícita', 'status' => 'Activa', 'estimated_amount' => 0]);

        $this->assertSame($company->id, $tender->company_id);
        $this->assertSame(1, Tender::count());

        // Un usuario sin empresas no ve nada.
        app(CompanyContext::class)->initialize(User::factory()->create(), [], null);
        $this->assertSame(0, Tender::count());

        app(CompanyContext::class)->forget();
        $this->assertSame(2, Tender::count());
    }
}
