<?php

namespace App\Http\Requests\Finance;

use App\Models\Institution;
use App\Models\PoaEntry;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Partida del POA de una institución. */
class PoaEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['annual_close_alert' => $this->boolean('annual_close_alert')]);
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'project' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'partida' => ['nullable', 'string', 'max:100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'probability' => ['required', 'integer', 'between:0,100'],
            'budgeted_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'executed_amount' => ['nullable', 'numeric', 'min:0', 'lte:budgeted_amount'],
            'estimated_date' => ['nullable', 'date_format:Y-m-d'],
            'annual_close_alert' => ['boolean'],
            'status' => ['required', Rule::in(PoaEntry::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'institution_id' => 'institución', 'project' => 'proyecto', 'year' => 'año', 'partida' => 'partida',
            'quarter' => 'trimestre', 'probability' => 'probabilidad', 'budgeted_amount' => 'monto presupuestado',
            'executed_amount' => 'monto ejecutado', 'estimated_date' => 'fecha estimada',
            'annual_close_alert' => 'alerta de cierre anual', 'status' => 'estado', 'notes' => 'notas',
        ];
    }

    public function messages(): array
    {
        return ['executed_amount.lte' => 'El monto ejecutado no puede superar el presupuestado.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        $data['executed_amount'] = $data['executed_amount'] ?? 0;

        return $data;
    }
}
