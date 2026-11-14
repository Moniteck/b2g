<?php

namespace App\Http\Requests\Quotes;

use Illuminate\Foundation\Http\FormRequest;

/** Registrar Ganada (cotización u oportunidad). */
class WinRequest extends FormRequest
{
    use AuthorizesSubject;

    protected function abilities(): array
    {
        return ['quote' => 'adjudicate', 'opportunity' => 'adjudicate'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'awarded_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'awarded_at' => ['required', 'date', 'before_or_equal:today'],
            'quote_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'awarded_amount.required' => 'Indique el monto adjudicado.',
            'awarded_amount.gt' => 'El monto adjudicado debe ser mayor que cero.',
            'awarded_at.required' => 'Indique la fecha de adjudicación.',
            'awarded_at.before_or_equal' => 'La fecha de adjudicación no puede ser futura.',
        ];
    }
}
