<?php

namespace App\Rules;

use App\Services\Tenancy\CompanyContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El `company_id` recibido es una empresa a la que el actor tiene acceso.
 *
 * Uso: 'company_id' => ['nullable', 'integer', new AllowedCompany]
 */
class AllowedCompany implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! app(CompanyContext::class)->canAccess((int) $value)) {
            $fail('La empresa seleccionada no es válida.');
        }
    }
}
