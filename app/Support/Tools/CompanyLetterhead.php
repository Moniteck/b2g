<?php

namespace App\Support\Tools;

use App\Models\Company;
use App\Services\Tenancy\CompanyContext;

/**
 * Datos de la empresa activa para membretes y para el contexto de la IA:
 * sus columnas (nombre, RTN, representante) más lo guardado en `settings`.
 */
final class CompanyLetterhead
{
    /** @return array<string, mixed> */
    public static function current(): array
    {
        $companyId = app(CompanyContext::class)->currentCompanyId();
        $company = $companyId !== null ? Company::query()->find($companyId) : null;

        if ($company === null) {
            return [];
        }

        $settings = is_array($company->settings) ? $company->settings : [];

        return array_filter([
            'name' => $company->name,
            'tax_id' => $company->tax_id,
            'representative' => $company->representative,
            'address' => $settings['address'] ?? null,
            'phone' => $settings['phone'] ?? $company->contact_phone,
            'email' => $settings['email'] ?? $company->contact_email,
        ], fn ($value) => filled($value));
    }
}
