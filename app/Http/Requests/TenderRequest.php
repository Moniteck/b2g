<?php

namespace App\Http\Requests;

use App\Models\Institution;
use App\Models\Tender;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Alta y edición manual de una licitación (y base de la confirmación de una captura).
 */
class TenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference' => is_string($this->input('reference')) ? strtoupper(trim($this->input('reference'))) : $this->input('reference'),
            'offer_submitted' => $this->boolean('offer_submitted'),
            'estimated_amount' => $this->input('estimated_amount') === null || $this->input('estimated_amount') === '' ? 0 : $this->input('estimated_amount'),
        ]);
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', new AllowedCompany('create')],
            'institution_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'reference' => ['required', 'string', 'max:100', $this->uniqueReference()],
            ...self::fieldRules(),
            'status' => ['required', Rule::in(Tender::STATUSES)],
            'estimated_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    /**
     * Reglas de los campos comunes a la carga manual y a la confirmación de una captura.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function fieldRules(): array
    {
        return [
            'process_name' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:1000'],
            'purchase_type' => ['nullable', 'string', 'max:255'],
            'purchasing_unit' => ['nullable', 'string', 'max:255'],
            'funding_source_type' => ['nullable', 'string', 'max:120'],
            'funding_source' => ['nullable', 'string', 'max:120'],
            'honducompras_stage' => ['nullable', 'string', 'max:120'],
            'acquisition_type' => ['nullable', 'string', 'max:120'],
            'offer_reception_place' => ['nullable', 'string', 'max:500'],
            'bid_document_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'offer_validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'published_at' => ['nullable', 'date'],
            'started_at' => ['nullable', 'date'],
            'clarification_deadline' => ['nullable', 'date'],
            'presentation_deadline' => ['nullable', 'date', 'after_or_equal:started_at'],
            'opening_at' => ['nullable', 'date', 'after_or_equal:presentation_deadline'],
            'closing_date' => ['nullable', 'date'],
            'source_url' => ['nullable', 'url', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'documents' => ['nullable', 'array', 'max:200'],
            'documents.*' => ['string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'offer_submitted' => ['boolean'],
            'result' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::fieldMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function fieldMessages(): array
    {
        return [
            'reference.unique' => 'Ya existe una licitación con ese expediente en la empresa.',
            'presentation_deadline.after_or_equal' => 'La recepción de ofertas no puede ser anterior a la fecha de inicio.',
            'opening_at.after_or_equal' => 'La apertura no puede ser anterior a la recepción de ofertas.',
        ];
    }

    public function attributes(): array
    {
        return self::fieldAttributes();
    }

    /**
     * @return array<string, string>
     */
    public static function fieldAttributes(): array
    {
        return [
            'reference' => 'expediente', 'title' => 'objeto', 'process_name' => 'nombre del proceso',
            'institution_id' => 'entidad', 'purchase_type' => 'modalidad', 'status' => 'estado',
            'estimated_amount' => 'monto estimado', 'purchasing_unit' => 'unidad de compra',
            'funding_source_type' => 'tipo de fuente', 'funding_source' => 'fuente',
            'honducompras_stage' => 'etapa en HonduCompras', 'acquisition_type' => 'tipo de adquisición',
            'offer_reception_place' => 'lugar de recepción', 'bid_document_fee' => 'valor de pliegos',
            'offer_validity_days' => 'vigencia de la oferta', 'started_at' => 'fecha de inicio',
            'clarification_deadline' => 'cierre de aclaraciones', 'presentation_deadline' => 'recepción de ofertas',
            'opening_at' => 'apertura', 'published_at' => 'publicación', 'closing_date' => 'cierre',
            'source_url' => 'enlace', 'contact_name' => 'contacto', 'contact_phone' => 'teléfono',
            'contact_email' => 'correo', 'delivery_terms' => 'plazo de entrega', 'payment_terms' => 'forma de pago',
            'requirements' => 'requisitos', 'observations' => 'observaciones', 'notes' => 'notas',
        ];
    }

    private function uniqueReference(): Unique
    {
        $companyId = $this->input('company_id') ?: app(CompanyContext::class)->currentCompanyId();

        return Rule::unique('tenders', 'reference')->where('company_id', $companyId)->ignore($this->route('tender'));
    }
}
