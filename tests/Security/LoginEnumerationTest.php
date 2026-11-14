<?php

namespace Tests\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Hallazgo: enumeración de cuentas por tiempo de respuesta en POST /login.
 *
 * Con un correo EXISTENTE y contraseña mala se calculan dos bcrypt (Auth::attempt y luego
 * AccessRequestService::loginStatus contra el hash de relleno); con un correo INEXISTENTE solo
 * uno (Auth::attempt no compara nada si no encuentra al usuario). Con bcrypt de coste 12 la
 * diferencia es de ~200-300 ms por intento: medible desde fuera aunque el mensaje sea el mismo.
 *
 * Esta prueba FALLA mientras la vulnerabilidad exista.
 */
#[Group('security')]
class LoginEnumerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_correo_existente_e_inexistente_cuestan_lo_mismo(): void
    {
        $this->memberOf(attributes: ['email' => 'existe@empresa.test', 'password' => 'ClaveValida123']);

        $existing = $this->hashChecksFor('existe@empresa.test');
        $missing = $this->hashChecksFor('no-existe@empresa.test');

        $this->assertSame($existing, $missing, "Comparaciones de contraseña: correo existente={$existing}, inexistente={$missing}. El tiempo de respuesta delata qué correos tienen cuenta.");
    }

    private function hashChecksFor(string $email): int
    {
        $counter = new class($this->app) extends HashManager
        {
            public int $checks = 0;

            public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
            {
                $this->checks++;

                return parent::check($value, $hashedValue, $options);
            }
        };

        $this->app->instance('hash', $counter);
        $this->app['auth']->forgetGuards();
        Facade::clearResolvedInstance('hash');

        $this->post('/login', ['email' => $email, 'password' => 'Incorrecta999']);

        return $counter->checks;
    }
}
