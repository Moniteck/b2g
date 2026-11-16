<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;

/**
 * Solo se atienden peticiones cuyo Host es el de APP_URL o un subdominio suyo (hallazgo B3):
 * así las URL absolutas que genera la aplicación (correos, enlaces firmados, OAuth) no pueden
 * apuntar al dominio que elija quien hace la petición. Un Host ajeno responde con error.
 *
 * El middleware de Laravel no actúa en pruebas; este sí (para poder probarlo) y solo se omite
 * en `local`, donde se entra por localhost o 127.0.0.1 indistintamente.
 */
class TrustHosts extends Middleware
{
    /**
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        return [$this->allSubdomainsOfApplicationUrl()];
    }

    protected function shouldSpecifyTrustedHosts(): bool
    {
        return ! $this->app->environment('local');
    }

    /**
     * Los hosts de confianza son estado estático de Symfony: se limpian al terminar la petición
     * para que no pasen a la siguiente (pruebas, Octane).
     */
    public function handle(Request $request, $next)
    {
        try {
            return parent::handle($request, $next);
        } finally {
            Request::setTrustedHosts([]);
        }
    }
}
