<?php

namespace App\Http\Requests\Admin;

use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de la empresa activa que salen en membretes, cotizaciones y PDF.
 * Las columnas de la empresa son opcionales (`sometimes`); el resto vive en `settings`.
 */
class SettingsRequest extends FormRequest
{
    /** Claves admitidas dentro de `companies.settings`. */
    public const SETTINGS_KEYS = ['address', 'city', 'phone', 'email', 'website', 'quote_footer'];

    public function authorize(): bool
    {
        return $this->user()?->can('manage-settings') ?? false;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->currentCompanyId();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('companies', 'tax_id')->ignore($companyId)],
            'representative' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'settings' => ['nullable', 'array'],
            'settings.address' => ['nullable', 'string', 'max:500'],
            'settings.city' => ['nullable', 'string', 'max:100'],
            'settings.phone' => ['nullable', 'string', 'max:50'],
            'settings.email' => ['nullable', 'email', 'max:255'],
            'settings.website' => ['nullable', 'string', 'max:255'],
            'settings.quote_footer' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escriba el nombre de la empresa.',
            'tax_id.unique' => 'Ya hay otra empresa con ese RTN.',
            'contact_email.email' => 'El correo no tiene un formato válido.',
            'settings.email.email' => 'El correo no tiene un formato válido.',
        ];
    }
}
