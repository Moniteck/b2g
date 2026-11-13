<?php

namespace App\Http\Requests\Contracts;

use App\Models\ContractDelivery;
use App\Models\Document;
use App\Rules\BelongsToActiveCompany;
use App\Support\Binaries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar una entrega, y marcarla como entregada con su evidencia.
 */
class ContractDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('followUp', $this->route('contract'));
    }

    public function rules(): array
    {
        if ($this->routeIs('contracts.deliveries.deliver')) {
            return [
                'delivered_at' => ['required', 'date', 'before_or_equal:tomorrow'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.Binaries::maxPdfKilobytes()],
            ];
        }

        return [
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit' => ['nullable', 'string', 'max:50'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(ContractDelivery::STATUSES)],
            'delivered_at' => ['nullable', 'date', 'before_or_equal:tomorrow'],
            'evidence_document_id' => ['nullable', 'integer', new BelongsToActiveCompany(Document::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'delivered_at.before_or_equal' => 'La fecha real de entrega no puede ser futura.',
            'evidence.mimes' => 'La evidencia debe ser un PDF o una imagen JPG o PNG.',
            'evidence.max' => 'La evidencia supera el tamaño permitido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'description' => 'descripción', 'quantity' => 'cantidad', 'unit' => 'unidad', 'due_date' => 'fecha comprometida',
            'status' => 'estado', 'delivered_at' => 'fecha real', 'evidence' => 'evidencia', 'notes' => 'notas',
        ];
    }
}
