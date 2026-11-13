<?php

namespace App\Http\Requests\Contracts;

use App\Models\ContractFollowUp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('followUp', $this->route('contract'));
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(ContractFollowUp::TYPES)],
            'description' => ['required', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
            'next_action' => ['nullable', 'string', 'max:255', 'required_with:next_action_at'],
            'next_action_at' => ['nullable', 'date', 'required_with:next_action'],
        ];
    }

    public function messages(): array
    {
        return [
            'next_action.required_with' => 'Describa la próxima acción.',
            'next_action_at.required_with' => 'Indique la fecha de la próxima acción.',
        ];
    }

    public function attributes(): array
    {
        return ['type' => 'tipo', 'description' => 'nota', 'occurred_at' => 'fecha', 'next_action' => 'próxima acción', 'next_action_at' => 'fecha de la próxima acción'];
    }
}
