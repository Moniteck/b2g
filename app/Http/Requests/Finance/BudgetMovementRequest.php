<?php

namespace App\Http\Requests\Finance;

use App\Models\BudgetMovement;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Movimiento manual del presupuesto revolvente. La referencia, si se indica,
 * es única dentro de la empresa activa.
 */
class BudgetMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'moved_at' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(BudgetMovement::MANUAL_TYPES)],
            'concept' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'reference' => [
                'nullable', 'string', 'max:100',
                Rule::unique('budget_movements', 'reference')->where('company_id', app(CompanyContext::class)->currentCompanyId()),
            ],
        ];
    }

    public function attributes(): array
    {
        return ['moved_at' => 'fecha', 'type' => 'tipo', 'concept' => 'concepto', 'amount' => 'monto', 'reference' => 'referencia'];
    }

    public function messages(): array
    {
        return [
            'reference.unique' => 'Ya hay un movimiento con esa referencia en la empresa.',
            'amount.gt' => 'El monto debe ser mayor que cero.',
        ];
    }
}
