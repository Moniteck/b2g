<?php

namespace App\Http\Requests\Finance;

use App\Models\Client;
use App\Models\Institution;
use App\Rules\BelongsToActiveCompany;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Factura por cobrar. El número es único dentro de la empresa activa.
 */
class CollectionInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $invoice = $this->route('collectionInvoice');

        return [
            'client_id' => ['nullable', 'integer', new BelongsToActiveCompany(Client::class)],
            'institution_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'number' => [
                'required', 'string', 'max:100',
                Rule::unique('collection_invoices', 'number')
                    ->where('company_id', app(CompanyContext::class)->currentCompanyId())
                    ->ignore($invoice?->id),
            ],
            'concept' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'issued_at' => ['required', 'date_format:Y-m-d'],
            'due_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:issued_at'],
            'interest_rate' => ['nullable', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'client_id' => 'cliente', 'institution_id' => 'institución', 'number' => 'número de factura',
            'concept' => 'concepto', 'amount' => 'monto', 'issued_at' => 'fecha de emisión',
            'due_at' => 'fecha de vencimiento', 'interest_rate' => 'tasa de interés', 'notes' => 'notas',
        ];
    }

    public function messages(): array
    {
        return [
            'number.unique' => 'Ya existe una factura con ese número en la empresa.',
            'due_at.after_or_equal' => 'El vencimiento no puede ser anterior a la emisión.',
            'amount.gt' => 'El monto debe ser mayor que cero.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        $data['interest_rate'] = $data['interest_rate'] ?? 0;

        return $data;
    }
}
