<?php

namespace Tests\Feature\Auth;

use App\Mail\AccessApprovedMail;
use App\Mail\AccessRequestedMail;
use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\Auth\AccessRequestService;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Licitar2026seguro';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        RateLimiter::clear('access-request:ana@empresa.test');
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Ana Pérez',
            'email' => 'ana@empresa.test',
            'phone' => '+504 9999-0000',
            'company' => null,
            'company_name' => 'Constructora del Valle',
            'position' => 'Analista de licitaciones',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'website' => '',
            ...$overrides,
        ];
    }

    public function test_the_request_screen_lists_active_companies_without_ids(): void
    {
        $active = Company::factory()->create(['name' => 'CAP Honduras', 'is_active' => true]);
        Company::factory()->create(['name' => 'Inactiva', 'is_active' => false]);

        $this->get('/solicitar-acceso')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('mode', 'register')
            ->has('companies', 1)
            ->where('companies.0.label', 'CAP Honduras')
            ->where('companies.0.value', AccessRequestService::companyToken($active->id)));

        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('mode', 'login'));
    }

    public function test_a_valid_request_stays_pending_and_cannot_log_in(): void
    {
        $this->from('/solicitar-acceso')->post('/solicitar-acceso', $this->payload())
            ->assertRedirect('/solicitar-acceso')->assertSessionHasNoErrors();

        $request = AccessRequest::query()->sole();
        $this->assertSame(AccessRequest::PENDING, $request->status);
        $this->assertNotSame(self::PASSWORD, $request->password);
        $this->assertDatabaseMissing('users', ['email' => 'ana@empresa.test']);
        $this->assertTrue(AuditLog::query()->where('action', 'acceso.solicitado')->exists());

        $this->from('/login')->post('/login', ['email' => 'ana@empresa.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email', 'access']);
        $this->assertSame('pending', session('errors')->first('access'));
        $this->assertGuest();
    }

    public function test_a_wrong_password_never_reveals_the_request(): void
    {
        $this->post('/solicitar-acceso', $this->payload());

        $this->from('/login')->post('/login', ['email' => 'ana@empresa.test', 'password' => 'otra-clave-123'])
            ->assertSessionHasErrors(['email' => 'Las credenciales no son válidas.'])
            ->assertSessionDoesntHaveErrors('access');
    }

    public function test_duplicate_email_answers_the_same_and_does_not_duplicate(): void
    {
        User::factory()->create(['email' => 'existe@empresa.test']);

        $first = $this->from('/solicitar-acceso')->post('/solicitar-acceso', $this->payload());
        $repeat = $this->from('/solicitar-acceso')->post('/solicitar-acceso', $this->payload(['name' => 'Ana Otra']));
        $existing = $this->from('/solicitar-acceso')->post('/solicitar-acceso', $this->payload(['email' => 'existe@empresa.test']));

        foreach ([$first, $repeat, $existing] as $response) {
            $response->assertRedirect('/solicitar-acceso')->assertSessionHasNoErrors();
        }

        $this->assertSame(1, AccessRequest::query()->count());
        $this->assertSame(1, User::query()->where('email', 'existe@empresa.test')->count());
    }

    public function test_validation_messages_are_in_spanish(): void
    {
        $this->post('/solicitar-acceso', $this->payload([
            'name' => '', 'email' => 'no-es-correo', 'company_name' => '', 'position' => '',
            'password' => 'corta', 'password_confirmation' => 'distinta',
        ]))->assertSessionHasErrors([
            'name' => 'Escribe tu nombre completo.',
            'email' => 'El correo no tiene un formato válido.',
            'company_name' => 'Elige tu empresa o escribe su nombre.',
            'position' => 'Escribe tu cargo.',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, AccessRequest::query()->count());
    }

    public function test_honeypot_answers_the_same_without_saving(): void
    {
        $this->from('/solicitar-acceso')->post('/solicitar-acceso', $this->payload(['website' => 'http://spam.test']))
            ->assertRedirect('/solicitar-acceso')->assertSessionHasNoErrors();

        $this->assertSame(0, AccessRequest::query()->count());
    }

    public function test_requests_are_throttled_by_email_and_by_ip(): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/solicitar-acceso', $this->payload())->assertSessionHasNoErrors();
        }

        $this->post('/solicitar-acceso', $this->payload())->assertSessionHasErrors('email');

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/solicitar-acceso', $this->payload(['email' => "otro{$attempt}@empresa.test"]));
        }

        $this->post('/solicitar-acceso', $this->payload(['email' => 'ultimo@empresa.test']))->assertStatus(429);
    }

    public function test_company_admins_get_a_bell_notice_and_an_email(): void
    {
        [$admin, $company] = $this->memberOf(role: 'admin');
        [$operator] = $this->memberOf($company, 'operator');
        $root = User::factory()->create(['is_root' => true]);

        $this->post('/solicitar-acceso', $this->payload(['company' => AccessRequestService::companyToken($company->id), 'company_name' => null]));

        $request = AccessRequest::query()->sole();
        $this->assertSame($company->id, $request->company_id);

        $feed = app(NotificationFeed::class)->forUser($admin->id, $company->id);
        $this->assertSame(1, $feed['unread']);
        $this->assertSame('Nueva solicitud de acceso', $feed['items'][0]['title']);
        $this->assertStringContainsString('Ana Pérez', $feed['items'][0]['body']);
        $this->assertSame('/configuracion/usuarios#solicitudes', $feed['items'][0]['href']);

        $this->assertSame(0, ReminderDelivery::query()->withoutGlobalScopes()->whereIn('user_id', [$operator->id, $root->id])->count());
        Mail::assertQueued(AccessRequestedMail::class, fn ($mail) => $mail->hasTo($admin->email));
        Mail::assertNotQueued(AccessRequestedMail::class, fn ($mail) => $mail->hasTo($operator->email));
    }

    public function test_requests_without_company_notify_the_root(): void
    {
        Company::factory()->create();
        $root = User::factory()->create(['is_root' => true]);

        $this->post('/solicitar-acceso', $this->payload());

        $delivery = ReminderDelivery::query()->withoutGlobalScopes()->where('user_id', $root->id)->sole();
        $this->assertSame($root->defaultCompanyId(), $delivery->company_id);
        Mail::assertQueued(AccessRequestedMail::class, fn ($mail) => $mail->hasTo($root->email));
    }

    public function test_admin_approves_with_company_and_role_and_the_person_can_log_in(): void
    {
        [$admin, $company] = $this->memberOf(role: 'admin');
        $this->post('/solicitar-acceso', $this->payload(['company' => AccessRequestService::companyToken($company->id)]));
        $request = AccessRequest::query()->sole();

        $this->actingInCompany($admin, $company)->get('/configuracion/usuarios')
            ->assertInertia(fn ($page) => $page->component('Admin/Users')
                ->loadDeferredProps(fn ($reload) => $reload->has('accessRequests', 1)->where('accessRequests.0.email', 'ana@empresa.test')));

        // Pendiente: no es miembro de la empresa.
        $this->assertSame(1, $company->users()->count());

        $this->actingInCompany($admin, $company)
            ->post("/configuracion/usuarios/solicitudes/{$request->id}/aprobar", ['company_id' => $company->id, 'role' => 'operator'])
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'ana@empresa.test')->sole();
        $this->assertSame('operator', $user->companyRole($company->id));
        $this->assertSame(AccessRequest::APPROVED, $request->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'acceso.aprobado')->exists());
        Mail::assertQueued(AccessApprovedMail::class, fn ($mail) => $mail->hasTo('ana@empresa.test'));

        // Idempotente: aprobar dos veces no crea otra cuenta.
        $this->actingInCompany($admin, $company)
            ->post("/configuracion/usuarios/solicitudes/{$request->id}/aprobar", ['company_id' => $company->id, 'role' => 'admin'])
            ->assertSessionHasErrors('request');
        $this->assertSame(1, User::query()->where('email', 'ana@empresa.test')->count());

        auth()->logout();
        $this->post('/login', ['email' => 'ana@empresa.test', 'password' => self::PASSWORD])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_rejects_with_an_optional_reason(): void
    {
        [$admin, $company] = $this->memberOf(role: 'admin');
        $this->post('/solicitar-acceso', $this->payload(['company' => AccessRequestService::companyToken($company->id)]));
        $request = AccessRequest::query()->sole();

        $this->actingInCompany($admin, $company)
            ->post("/configuracion/usuarios/solicitudes/{$request->id}/rechazar", ['reason' => 'No trabaja con nosotros.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(AccessRequest::REJECTED, $request->fresh()->status);
        $this->assertSame('No trabaja con nosotros.', $request->fresh()->rejection_reason);
        $this->assertDatabaseMissing('users', ['email' => 'ana@empresa.test']);
        Mail::assertNotQueued(AccessApprovedMail::class);

        auth()->logout();
        $this->from('/login')->post('/login', ['email' => 'ana@empresa.test', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertSame('rejected', session('errors')->first('access'));
        $this->assertGuest();
    }

    public function test_admins_only_see_and_review_requests_for_their_companies(): void
    {
        [$adminA, $companyA] = $this->memberOf(role: 'admin');
        [$adminB, $companyB] = $this->memberOf(role: 'admin');
        $root = User::factory()->create(['is_root' => true]);

        $this->post('/solicitar-acceso', $this->payload(['company' => AccessRequestService::companyToken($companyA->id)]));
        RateLimiter::clear('access-request:sin@empresa.test');
        $this->post('/solicitar-acceso', $this->payload(['email' => 'sin@empresa.test']));
        $forA = AccessRequest::query()->where('email', 'ana@empresa.test')->sole();
        $orphan = AccessRequest::query()->where('email', 'sin@empresa.test')->sole();

        $this->actingInCompany($adminB, $companyB)->get('/configuracion/usuarios')
            ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->has('accessRequests', 0)));
        $this->actingInCompany($adminA, $companyA)->get('/configuracion/usuarios')
            ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->has('accessRequests', 1)));
        $this->actingInCompany($root, $companyA)->get('/configuracion/usuarios')
            ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->has('accessRequests', 2)));

        $this->actingInCompany($adminB, $companyB)
            ->post("/configuracion/usuarios/solicitudes/{$forA->id}/aprobar", ['company_id' => $companyB->id, 'role' => 'viewer'])
            ->assertNotFound();
        $this->actingInCompany($adminA, $companyA)
            ->post("/configuracion/usuarios/solicitudes/{$orphan->id}/rechazar")
            ->assertNotFound();
        // Un admin no aprueba hacia una empresa que no administra.
        $this->actingInCompany($adminA, $companyA)
            ->post("/configuracion/usuarios/solicitudes/{$forA->id}/aprobar", ['company_id' => $companyB->id, 'role' => 'viewer'])
            ->assertSessionHasErrors('company_id');

        // La raíz aprueba la que no eligió empresa hacia la que decida.
        $this->actingInCompany($root, $companyA)
            ->post("/configuracion/usuarios/solicitudes/{$orphan->id}/aprobar", ['company_id' => $companyB->id, 'role' => 'viewer'])
            ->assertSessionHasNoErrors();
        $this->assertSame('viewer', User::query()->where('email', 'sin@empresa.test')->sole()->companyRole($companyB->id));
    }

    public function test_viewers_and_operators_cannot_review_requests(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $this->post('/solicitar-acceso', $this->payload(['company' => AccessRequestService::companyToken($company->id)]));
        $request = AccessRequest::query()->sole();

        $this->actingInCompany($viewer, $company)
            ->post("/configuracion/usuarios/solicitudes/{$request->id}/aprobar", ['company_id' => $company->id, 'role' => 'admin'])
            ->assertForbidden();
        $this->assertSame(AccessRequest::PENDING, $request->fresh()->status);
    }
}
