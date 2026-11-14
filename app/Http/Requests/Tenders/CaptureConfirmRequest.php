<?php

namespace App\Http\Requests\Tenders;

use App\Http\Requests\TenderRequest;
use App\Models\Institution;
use App\Models\Tender;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Confirmación de la revisión de una captura de HonduCompras.
 *
 * La unicidad del expediente no se valida aquí: si ya existe, el servicio exige marcar
 * «Actualizar la existente» y entonces actualiza en lugar de duplicar.
 */
class CaptureConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference' => is_string($this->input('reference')) ? strtoupper(trim($this->input('reference'))) : $this->input('reference'),
            'create_opportunity' => $this->boolean('create_opportunity'),
            'update_existing' => $this->boolean('update_existing'),
        ]);
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
            ...TenderRequest::fieldRules(),
            'estimated_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'institution_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'new_institution_name' => ['nullable', 'required_without:institution_id', 'string', 'max:255'],
            'new_institution_acronym' => ['nullable', 'string', 'max:30'],
            'create_opportunity' => ['boolean'],
            'update_existing' => ['boolean'],
            'update_tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->input('clarification_deadline') && ! $this->input('presentation_deadline')) {
                $validator->errors()->add('presentation_deadline', 'Indica al menos la recepción de ofertas o el cierre de aclaraciones.');
            }
        }];
    }

    public function messages(): array
    {
        return TenderRequest::fieldMessages() + [
            'new_institution_name.required_without' => 'Elige una institución del catálogo o escribe el nombre de una nueva.',
        ];
    }

    public function attributes(): array
    {
        return TenderRequest::fieldAttributes() + ['new_institution_name' => 'nueva institución'];
    }
}
