<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Las pantallas del CRM requieren autenticacion: un visitante sin sesion
     * es redirigido al inicio de sesion y un usuario administrador accede.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/')->assertOk();
    }
}
