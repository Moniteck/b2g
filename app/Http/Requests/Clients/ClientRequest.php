<?php

namespace App\Http\Requests\Clients;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de los datos y el contacto de un cliente. No hay alta manual: los
 * clientes nacen de una adjudicación. El RTN es único dentro de la empresa.
 */
class ClientRequest extends FormRequest
{
    public const STATUSES = ['Activo', 'Inactivo'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $taxId = $this->input('tax_id');

        $this->merge(['tax_id' => is_string($taxId) && trim($taxId) !== '' ? trim($taxId) : null]);
    }

    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        return [
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50', Rule::unique('clients', 'tax_id')
                ->where('company_id', $client->company_id)
                ->ignore($client->id)],
            'sector' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'tax_id.unique' => 'Ya hay otro cliente con ese RTN en la empresa.',
            'name.required' => 'Escriba el nombre del cliente.',
            'email.email' => 'El correo no tiene un formato válido.',
        ];
    }

    public function attributes(): array
    {
        return ['tax_id' => 'RTN', 'name' => 'nombre', 'email' => 'correo', 'phone' => 'teléfono', 'status' => 'estado'];
    }
}
