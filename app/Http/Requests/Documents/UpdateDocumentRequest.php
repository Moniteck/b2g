<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Validation\Rule;

/** Editar los datos de un documento (sin cambiar su archivo). */
class UpdateDocumentRequest extends DocumentRequest
{
    public function rules(): array
    {
        $document = $this->route('document');

        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => $document instanceof Document && $document->kind === 'empresa'
                ? ['required', Rule::in(array_keys(Document::CATEGORIES))]
                : ['nullable', 'string', 'max:100'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + ['name.required' => 'Escribe el nombre del documento.'];
    }
}
