<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** Ingreso de caja registrado a mano (no proviene de una factura de cobro). */
class CashIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'concept' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'received_at' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return ['concept' => 'concepto', 'amount' => 'monto', 'received_at' => 'fecha de recepción', 'notes' => 'notas'];
    }

    public function messages(): array
    {
        return ['amount.gt' => 'El monto debe ser mayor que cero.'];
    }
}
