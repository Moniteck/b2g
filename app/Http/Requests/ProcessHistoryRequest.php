<?php

namespace App\Http\Requests;

use App\Models\Institution;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Proceso del historial de HonduCompras de la empresa activa.
 */
class ProcessHistoryRequest extends FormRequest
{
    public const RESULTS = ['Adjudicado', 'No adjudicado', 'Desierto', 'Cancelado', 'En evaluación', 'No participó'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['participated' => $this->boolean('participated')]);
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'process_number' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'modality' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'participated' => ['required', 'boolean'],
            'decision' => ['nullable', 'string', 'max:100'],
            'estimated_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'offered_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'result' => ['nullable', 'string', Rule::in(self::RESULTS)],
            'cause' => ['nullable', 'string', 'max:255'],
            'published_at' => ['nullable', 'date'],
            'closed_at' => ['nullable', 'date', 'after_or_equal:published_at'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'institution_id' => 'institución', 'process_number' => 'número de proceso', 'name' => 'nombre del proceso',
            'modality' => 'modalidad', 'category' => 'categoría', 'participated' => 'participó', 'decision' => 'decisión',
            'estimated_amount' => 'monto estimado', 'offered_amount' => 'monto ofertado', 'result' => 'resultado',
            'cause' => 'causa', 'published_at' => 'fecha de publicación', 'closed_at' => 'fecha de cierre', 'notes' => 'notas',
        ];
    }

    public function messages(): array
    {
        return ['closed_at.after_or_equal' => 'La fecha de cierre no puede ser anterior a la de publicación.'];
    }
}
