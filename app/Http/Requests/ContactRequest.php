<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta y edición de contactos de una institución (catálogo compartido).
 */
class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'institution_id.required' => 'Elija la institución del contacto.',
            'institution_id.exists' => 'La institución elegida no existe.',
            'name.required' => 'Escriba el nombre del contacto.',
            'email.email' => 'El correo no tiene un formato válido.',
        ];
    }

    public function attributes(): array
    {
        return ['institution_id' => 'institución', 'name' => 'nombre', 'position' => 'cargo', 'email' => 'correo', 'phone' => 'teléfono'];
    }
}
