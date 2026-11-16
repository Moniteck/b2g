<?php

namespace App\Http\Requests\Directory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de instituciones compradoras (catálogo compartido del grupo).
 * La creación rápida por JSON (importación de licitaciones) solo exige el nombre.
 */
class InstitutionRequest extends FormRequest
{
    public const TYPES = ['Gobierno central', 'Descentralizada', 'Municipalidad', 'Autónoma', 'Fuerzas Armadas', 'Salud', 'Educación', 'Otra'];

    public const POTENTIALS = ['ALTO', 'MEDIO', 'BAJO'];

    public const STATUSES = ['Activa', 'Inactiva'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim(preg_replace('/\s+/u', ' ', $this->input('name'))) : $this->input('name'),
            'status' => $this->input('status') ?: 'Activa',
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('institutions', 'name')->ignore($this->route('institution'))],
            'acronym' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'potential' => ['nullable', Rule::in(self::POTENTIALS)],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escriba el nombre de la institución.',
            'name.unique' => 'Ya existe una institución con ese nombre.',
            'email.email' => 'El correo no tiene un formato válido.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'acronym' => 'siglas', 'type' => 'tipo', 'potential' => 'potencial', 'status' => 'estado', 'location' => 'ubicación'];
    }
}
