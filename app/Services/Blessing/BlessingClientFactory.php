<?php

namespace App\Services\Blessing;

use App\Models\Company;

/**
 * Elige el cliente de Blessing de una empresa:
 *
 *  1. URL base y token configurados → HttpBlessingClient (la API real).
 *  2. Empresa marcada como demostración (`settings.blessing.demo`, la pone
 *     `php artisan blessing:demo`) y APP_ENV local o testing → FakeBlessingClient.
 *  3. Si no → NullBlessingClient («Conexión con Blessing pendiente de configurar»).
 *
 * En pruebas se puede forzar un cliente: `$this->app->instance(FakeBlessingClient::class, $fake)`
 * hace que toda empresa use ese cliente falso.
 */
class BlessingClientFactory
{
    public const DEMO_ENVIRONMENTS = ['local', 'testing'];

    public function forCompany(Company $company): BlessingClient
    {
        if ($this->demoAllowed() && app()->bound(FakeBlessingClient::class)) {
            return app(FakeBlessingClient::class);
        }

        $baseUrl = trim((string) $company->blessing_base_url);
        $token = trim((string) $company->blessing_token);

        if ($baseUrl !== '' && $token !== '') {
            return new HttpBlessingClient($baseUrl, $token, $company->blessing_price_list ?: null);
        }

        if ($this->isDemo($company) && $this->demoAllowed()) {
            return new FakeBlessingClient;
        }

        return new NullBlessingClient;
    }

    /** `api`, `demo` o `ninguno`, sin crear el cliente. */
    public function modeOf(Company $company): string
    {
        return $this->forCompany($company)->source();
    }

    public function isDemo(Company $company): bool
    {
        return (bool) (($company->settings ?? [])['blessing']['demo'] ?? false);
    }

    public function demoAllowed(): bool
    {
        return app()->environment(self::DEMO_ENVIRONMENTS);
    }
}
