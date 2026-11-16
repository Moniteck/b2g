<?php

namespace Tests\Feature\Shell;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Las pantallas del CRM requieren autenticación: un visitante sin sesión
     * es redirigido al inicio de sesión y un miembro de una empresa accede.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertRedirect(route('login'));

        [$user] = $this->memberOf();

        $this->actingAs($user)->get('/')->assertOk();
    }
}
