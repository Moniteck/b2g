<?php

namespace Tests\Security;

use App\Models\AccessRequest;
use App\Models\User;
use App\Services\Auth\AccessRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Hallazgos de autenticación:
 *
 * 1. «Solicitar acceso» no comprueba que quien la envía controle el correo. Cualquiera puede pedir
 *    acceso como `gerente@empresa-cliente.hn`; si un administrador aprueba confiando en el correo,
 *    la cuenta con esa identidad queda en manos de quien eligió la contraseña.
 * 2. `back()` usa la cabecera Referer: una respuesta de error del login redirige a un dominio
 *    ajeno si la petición trae ese Referer (redirección abierta, explotable solo combinada con
 *    otro fallo porque el formulario exige token CSRF).
 *
 * Estas pruebas FALLAN mientras la vulnerabilidad exista.
 */
#[Group('security')]
class AuthenticationAbuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_solicitud_aprobada_no_da_acceso_sin_verificar_el_correo(): void
    {
        Mail::fake();
        RateLimiter::clear('access-request:director@cliente.test');
        [$admin, $company] = $this->memberOf();

        // El atacante usa el correo de otra persona: nunca recibe ni abre ningún enlace.
        $this->post('/solicitar-acceso', [
            'name' => 'Director Suplantado', 'email' => 'director@cliente.test', 'company' => AccessRequestService::companyToken($company->id),
            'position' => 'Director', 'password' => 'ClaveAtacante123', 'password_confirmation' => 'ClaveAtacante123', 'website' => '',
        ])->assertSessionHasNoErrors();

        $request = AccessRequest::query()->where('email', 'director@cliente.test')->firstOrFail();
        $this->actingInCompany($admin, $company)
            ->post("/configuracion/usuarios/solicitudes/{$request->id}/aprobar", ['company_id' => $company->id, 'role' => 'operator'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(User::query()->where('email', 'director@cliente.test')->first(), 'Control: la cuenta se creó.');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/login', ['email' => 'director@cliente.test', 'password' => 'ClaveAtacante123']);

        $this->assertNotSame('director@cliente.test', auth()->user()?->email, 'Quien eligió la contraseña entra con un correo que nunca demostró controlar.');
    }

    public function test_los_errores_del_login_no_redirigen_a_un_dominio_ajeno(): void
    {
        $response = $this->withHeader('Referer', 'https://evil.test/phish')
            ->post('/login', ['email' => 'nadie@empresa.test', 'password' => 'x']);

        $location = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('evil.test', $location, "El login redirigió a: {$location}");
    }
}
