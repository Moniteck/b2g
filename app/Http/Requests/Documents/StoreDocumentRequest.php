<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Models\Quote;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Validation\Rule;

/** Subir un documento al Directorio Institucional (o, por compatibilidad, a una cotización). */
class StoreDocumentRequest extends DocumentRequest
{
    public function rules(): array
    {
        $kind = $this->input('kind') ?: 'empresa';

        return [
            'company_id' => ['nullable', 'integer', new AllowedCompany('create')],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'file' => ['required'],
            'name' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', Rule::in(array_keys(Document::KINDS))],
            'category' => $kind === 'empresa'
                ? ['required', Rule::in(array_keys(Document::CATEGORIES))]
                : ['nullable', 'string', 'max:100'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'required_for' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'cotizacion_id' => ['nullable', 'integer', new BelongsToActiveCompany(Quote::class)],
        ];
    }
}
