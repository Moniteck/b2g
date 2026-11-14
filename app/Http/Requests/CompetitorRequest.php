<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Competidor del catálogo compartido del grupo.
 */
class CompetitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tax_id' => filled($this->input('tax_id')) ? trim((string) $this->input('tax_id')) : null,
            'institutions' => collect((array) $this->input('institutions', []))->map(fn ($name) => trim((string) $name))->filter()->unique()->values()->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('competitors', 'name')->ignore($this->route('competitor'))],
            'tax_id' => ['nullable', 'string', 'max:100', Rule::unique('competitors', 'tax_id')->ignore($this->route('competitor'))],
            'category' => ['nullable', 'string', 'max:100'],
            'wins' => ['required', 'integer', 'min:0', 'max:100000'],
            'losses' => ['required', 'integer', 'min:0', 'max:100000'],
            'institutions' => ['nullable', 'array', 'max:50'],
            'institutions.*' => ['string', 'max:255'],
            'average_award_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'last_participation_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre', 'tax_id' => 'RTN', 'category' => 'categoría', 'wins' => 'adjudicaciones',
            'losses' => 'no adjudicaciones', 'institutions' => 'instituciones', 'average_award_price' => 'precio promedio adjudicado',
            'last_participation_at' => 'última participación', 'notes' => 'notas',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un competidor con ese nombre.',
            'tax_id.unique' => 'Ya existe un competidor con ese RTN.',
        ];
    }
}
