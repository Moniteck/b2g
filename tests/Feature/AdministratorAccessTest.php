<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministratorAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        // Contraseña generada en cada corrida: nunca un literal en el repositorio.
        $this->password = Str::password(16);
        RateLimiter::clear('admin@crm-b2g.test|127.0.0.1');
    }

    public function test_administrator_can_authenticate_with_the_created_credentials(): void
    {
        $admin = User::factory()->create(['email' => 'admin@crm-b2g.test', 'password' => $this->password]);

        $this->post('/login', ['email' => 'admin@crm-b2g.test', 'password' => $this->password])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_company_administrator_reaches_every_protected_screen(): void
    {
        [$admin] = $this->memberOf(role: 'admin');

        $routes = [
            '/', '/reportes', '/agente-legal', '/pipeline', '/clientes', '/cotizaciones',
            '/inventario', '/ia-licitaciones', '/historial', '/licitaciones', '/directorio',
            '/contactos', '/post-adjudicacion', '/inteligencia-competitiva',
            '/relaciones-institucionales', '/presupuesto-revolvente', '/flujo-caja',
            '/alertas-poa', '/consorcios', '/matriz-cumplimiento', '/calculadora-oferta',
            '/generador-documentos', '/cobros', '/documentos', '/kpis', '/configuracion',
            '/configuracion/usuarios', '/configuracion/diagnostico', '/empresas',
            '/sops', '/capacitacion', '/notificaciones', '/calendario',
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($admin)->get($route);
            $this->assertTrue($response->isSuccessful(), "{$route} respondió {$response->getStatusCode()}");
        }
    }

    public function test_operator_cannot_open_admin_only_sections(): void
    {
        [$operator] = $this->memberOf(role: 'operator');

        $this->actingAs($operator)->get('/configuracion')->assertForbidden();
        $this->actingAs($operator)->get('/configuracion/usuarios')->assertForbidden();
        $this->actingAs($operator)->get('/configuracion/diagnostico')->assertForbidden();
    }

    public function test_user_without_company_cannot_open_the_application(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertForbidden();
    }

    public function test_seeding_is_not_available_from_the_web(): void
    {
        [$admin] = $this->memberOf(role: 'admin');

        $this->actingAs($admin)->post('/configuracion/seed')->assertNotFound();
    }

    public function test_repeated_failed_logins_are_locked_out(): void
    {
        User::factory()->create(['email' => 'admin@crm-b2g.test', 'password' => $this->password]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'admin@crm-b2g.test', 'password' => 'incorrecta']);
        }

        $this->post('/login', ['email' => 'admin@crm-b2g.test', 'password' => $this->password])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
