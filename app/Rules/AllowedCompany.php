<?php

namespace App\Rules;

use App\Services\Tenancy\CompanyContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El `company_id` recibido es una empresa a la que el actor tiene acceso y,
 * si se indica un permiso de `App\Support\Permissions`, en la que lo tiene.
 *
 * Uso:
 *   'company_id' => ['required', 'integer', new AllowedCompany]                        // solo acceso
 *   'company_id' => ['nullable', 'integer', new AllowedCompany(Permissions::CREATE)]   // puede escribir en ella
 */
class AllowedCompany implements ValidationRule
{
    public function __construct(protected ?string $permission = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! app(CompanyContext::class)->canAccess((int) $value)) {
            $fail('La empresa seleccionada no es válida.');

            return;
        }

        $user = request()->user();

        if ($this->permission !== null && $user !== null && ! $user->hasPermission($this->permission, (int) $value)) {
            $fail('No tiene permiso para operar en la empresa seleccionada.');
        }
    }
}
