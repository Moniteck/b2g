<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdministratorAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('admin@crm-b2g.test|127.0.0.1');
    }

    public function test_administrator_can_authenticate_with_the_created_credentials(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@crm-b2g.test',
            'password' => 'Crm#3db83782',
            'role' => 'admin',
        ]);

        $this->post('/login', ['email' => 'admin@crm-b2g.test', 'password' => 'Crm#3db83782'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_administrator_reaches_every_protected_screen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $routes = [
            '/', '/reportes', '/agente-legal', '/pipeline', '/clientes', '/cotizaciones',
            '/inventario', '/ia-licitaciones', '/historial', '/licitaciones', '/directorio',
            '/contactos', '/post-adjudicacion', '/inteligencia-competitiva',
            '/relaciones-institucionales', '/presupuesto-revolvente', '/flujo-caja',
            '/alertas-poa', '/consorcios', '/matriz-cumplimiento', '/calculadora-oferta',
            '/generador-documentos', '/cobros', '/documentos', '/kpis', '/configuracion',
            '/sops', '/capacitacion', '/notificaciones', '/calendario',
        ];

        foreach ($routes as $route) {
            $this->actingAs($admin)->get($route)->assertSuccessful();
        }
    }

    public function test_administrator_reaches_admin_only_sections(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/configuracion')->assertSuccessful();
        $this->actingAs($admin)->get('/configuracion/usuarios')->assertSuccessful();
        $this->actingAs($admin)->get('/kpis')->assertSuccessful();
    }

    public function test_operator_cannot_open_admin_only_sections(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);

        $this->actingAs($operator)->get('/configuracion')->assertForbidden();
        $this->actingAs($operator)->get('/configuracion/usuarios')->assertForbidden();
    }

    public function test_repeated_failed_logins_are_locked_out(): void
    {
        User::factory()->create([
            'email' => 'admin@crm-b2g.test',
            'password' => 'Crm#3db83782',
            'role' => 'admin',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'admin@crm-b2g.test', 'password' => 'incorrecta']);
        }

        $response = $this->post('/login', ['email' => 'admin@crm-b2g.test', 'password' => 'Crm#3db83782']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
