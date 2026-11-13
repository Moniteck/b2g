<?php

namespace App\Http\Requests\Contracts;

use App\Services\Contracts\ContractStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado. Las transiciones y el permiso de administrador los decide `ContractStatus`
 * en `ContractService::changeStatus`.
 */
class ContractStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('contract'));
    }

    public function rules(): array
    {
        $closing = $this->routeIs('contracts.complete');

        return [
            'status' => [$closing ? 'nullable' : 'required', Rule::in(ContractStatus::ALL)],
            'reason' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => in_array($this->input('status'), [ContractStatus::CANCELLED, ContractStatus::SUSPENDED], true))],
            'confirm' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Elija el nuevo estado.',
            'status.in' => 'El estado elegido no existe.',
            'reason.required' => 'Indique el motivo.',
        ];
    }

    public function attributes(): array
    {
        return ['status' => 'estado', 'reason' => 'motivo'];
    }
}
