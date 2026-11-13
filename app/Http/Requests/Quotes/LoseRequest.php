<?php

namespace App\Http\Requests\Quotes;

use Illuminate\Foundation\Http\FormRequest;

/** Registrar Perdida: exige quién ganó (participante o nombre escrito) y el valor de su oferta. */
class LoseRequest extends FormRequest
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
            'winner_participant_id' => ['nullable', 'integer'],
            'winner_name' => ['required_without:winner_participant_id', 'nullable', 'string', 'max:255'],
            'winner_tax_id' => ['nullable', 'string', 'max:100'],
            'winner_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'lost_reason' => ['nullable', 'string', 'max:2000'],
            'awarded_at' => ['nullable', 'date', 'before_or_equal:today'],
            'quote_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'winner_name.required_without' => 'Indique quién ganó la oferta.',
            'winner_amount.required' => 'Indique el valor de la oferta ganadora.',
            'winner_amount.gt' => 'El valor de la oferta ganadora debe ser mayor que cero.',
            'awarded_at.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }
}
