<?php

namespace App\Http\Requests\Tools;

use App\Models\Document;
use App\Models\Tender;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta (con sus requisitos) o cambio de nombre de una matriz documental.
 */
class ComplianceMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $matrix = $this->route('complianceMatrix');
        $tenderId = $matrix === null ? $this->input('tender_id') : $matrix->tender_id;

        return [
            'tender_id' => $matrix === null ? ['required', 'integer', new BelongsToActiveCompany(Tender::class)] : ['prohibited'],
            'name' => ['required', 'string', 'max:255', Rule::unique('compliance_matrices', 'name')->where('tender_id', $tenderId)->ignore($matrix)],
            'items' => $matrix === null ? ['required', 'array', 'min:1', 'max:100'] : ['prohibited'],
            'items.*.requirement' => ['required', 'string', 'max:255'],
            'items.*.document_id' => ['nullable', 'integer', new BelongsToActiveCompany(Document::class)],
            'items.*.renewal_due_at' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tender_id' => 'licitación', 'name' => 'nombre', 'items' => 'requisitos',
            'items.*.requirement' => 'requisito', 'items.*.document_id' => 'documento',
            'items.*.renewal_due_at' => 'vencimiento', 'items.*.notes' => 'notas',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Esa licitación ya tiene una matriz con ese nombre.',
            'items.required' => 'Agrega al menos un requisito.',
            'items.min' => 'Agrega al menos un requisito.',
        ];
    }
}
