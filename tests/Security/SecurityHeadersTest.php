<?php

namespace Tests\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Hallazgo: las páginas de la aplicación no envían cabeceras de seguridad (solo la pantalla de
 * consentimiento OAuth las tiene). Sin `frame-ancestors`/`X-Frame-Options` cualquier sitio puede
 * enmarcar el CRM (clickjacking sobre «Eliminar», «Aprobar solicitud», etc.).
 *
 * Además, la aplicación responde a cualquier cabecera Host (no hay `trustHosts`), así que las URL
 * absolutas que genera pueden apuntar al dominio que elija el cliente.
 *
 * Estas pruebas FALLAN mientras la vulnerabilidad exista.
 */
#[Group('security')]
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_paginas_publicas_envian_cabeceras_de_seguridad(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), 'Falta X-Content-Type-Options: nosniff');
        $this->assertNotNull($response->headers->get('Referrer-Policy'), 'Falta Referrer-Policy');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $xfo = strtoupper((string) $response->headers->get('X-Frame-Options'));
        $this->assertTrue(
            str_contains($csp, "frame-ancestors 'none'") || str_contains($csp, "frame-ancestors 'self'") || in_array($xfo, ['DENY', 'SAMEORIGIN'], true),
            'La página puede enmarcarse desde otro sitio (falta frame-ancestors o X-Frame-Options).'
        );
    }

    public function test_las_paginas_autenticadas_no_se_pueden_enmarcar(): void
    {
        [$admin] = $this->memberOf();

        $response = $this->actingAs($admin)->get('/configuracion/usuarios');

        $response->assertOk();
        $xfo = strtoupper((string) $response->headers->get('X-Frame-Options'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertTrue(
            in_array($xfo, ['DENY', 'SAMEORIGIN'], true) || str_contains($csp, 'frame-ancestors'),
            'La pantalla de Usuarios (aprobar, restablecer contraseñas) puede enmarcarse: clickjacking.'
        );
    }

    public function test_un_host_ajeno_no_es_aceptado(): void
    {
        config(['app.url' => 'https://crm.example.test']);

        $response = $this->get('http://evil.test/login');

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode(), 'La aplicación acepta cualquier cabecera Host (configure trustHosts).');
    }
}
