<?php

namespace Tests\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Hallazgo: cambiar la contraseña (o que un administrador la restablezca) NO cierra las demás
 * sesiones abiertas. Quien robó una sesión (o un equipo compartido) sigue dentro aunque la
 * víctima cambie la clave. No hay `AuthenticateSession` (auth.session) ni `logoutOtherDevices`.
 *
 * Verificado también en vivo contra http://127.0.0.1:8000: sesión B sigue en 200 tras el cambio.
 *
 * Estas pruebas FALLAN mientras la vulnerabilidad exista.
 */
#[Group('security')]
class SessionInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_cambiar_la_contrasena_cierra_las_otras_sesiones(): void
    {
        [$user] = $this->memberOf(role: 'viewer', attributes: ['email' => 'victima@empresa.test', 'password' => 'ClaveVieja123']);

        $other = $this->loginAndGetSessionCookie('victima@empresa.test', 'ClaveVieja123');
        $this->assertSame(200, $this->getWithSession($other)->getStatusCode(), 'La sesión del otro equipo debería estar activa antes del cambio.');
        $this->assertSame(302, $this->getWithSession('cookie-invalida')->getStatusCode(), 'Control: sin una cookie válida la petición debe ir al login.');

        $this->fresh();
        $this->actingAs($user->fresh())->put('/mi-cuenta/contrasena', [
            'current_password' => 'ClaveVieja123',
            'password' => 'ClaveNueva456',
            'password_confirmation' => 'ClaveNueva456',
        ])->assertSessionHasNoErrors();

        $this->assertNotSame(200, $this->getWithSession($other)->getStatusCode(), 'La otra sesión sigue abierta después de cambiar la contraseña.');
    }

    public function test_el_restablecimiento_por_un_administrador_cierra_las_sesiones_del_usuario(): void
    {
        [$admin, $company] = $this->memberOf();
        [$user] = $this->memberOf($company, 'operator', ['email' => 'operador@empresa.test', 'password' => 'ClaveVieja123']);
        $user->companies()->updateExistingPivot($company->id, ['is_default' => true]);

        $stolen = $this->loginAndGetSessionCookie('operador@empresa.test', 'ClaveVieja123');

        $this->fresh();
        $this->actingInCompany($admin, $company)->post("/configuracion/usuarios/{$user->id}/contrasena")->assertRedirect();
        $this->assertFalse(Hash::check('ClaveVieja123', $user->fresh()->password), 'El restablecimiento no se aplicó (la prueba no es válida).');

        $this->assertNotSame(200, $this->getWithSession($stolen)->getStatusCode(), 'La sesión robada sigue abierta después del restablecimiento.');
    }

    private function loginAndGetSessionCookie(string $email, string $password): string
    {
        $this->fresh();
        $response = $this->post('/login', ['email' => $email, 'password' => $password]);
        $response->assertRedirect();

        return (string) $response->getCookie(config('session.cookie'), false)->getValue();
    }

    private function getWithSession(string $encryptedSessionCookie)
    {
        $this->fresh();

        return $this->call('GET', '/', [], [config('session.cookie') => $encryptedSessionCookie]);
    }

    /** Olvida guardias y sesión en memoria para que cada petición dependa solo de su cookie. */
    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('session.store');
        $this->app['session']->forgetDrivers();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app->forgetInstance(User::class);
    }
}
