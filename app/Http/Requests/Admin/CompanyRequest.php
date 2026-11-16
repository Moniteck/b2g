<?php

namespace App\Http\Requests\Admin;

use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de empresas del grupo (solo raíz). El token de Blessing vacío
 * conserva el guardado; para borrarlo se envía `clear_blessing_token`.
 */
class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'tax_id'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => trim($value) === '' ? null : trim($value)]);
            }
        }
    }

    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('companies', 'code')->ignore($company)],
            'tax_id' => ['nullable', 'string', 'max:50', Rule::unique('companies', 'tax_id')->ignore($company)],
            'representative' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'address' => ['nullable', 'string', 'max:500'],
            'blessing_base_url' => ['nullable', 'url:https,http', 'max:255', new SafeExternalUrl],
            'blessing_token' => ['nullable', 'string', 'max:2000'],
            'clear_blessing_token' => ['nullable', 'boolean'],
            'blessing_price_list' => ['nullable', 'string', 'max:100'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prices_include_tax' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escriba el nombre de la empresa.',
            'code.unique' => 'Ya hay otra empresa con ese código.',
            'tax_id.unique' => 'Ya hay otra empresa con ese RTN.',
            'contact_email.email' => 'El correo no tiene un formato válido.',
            'blessing_base_url.url' => 'Escriba una URL completa, p. ej. https://api.blessing.hn.',
            'tax_rate.max' => 'La tasa no puede pasar de 100 %.',
        ];
    }

    public function attributes(): array
    {
        return ['tax_rate' => 'tasa de ISV', 'tax_id' => 'RTN', 'code' => 'código'];
    }
}
