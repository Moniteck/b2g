<?php

namespace App\Http\Requests\Tools;

use App\Models\Document;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;

class ComplianceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['completed' => $this->boolean('completed')]);
    }

    public function rules(): array
    {
        return [
            'requirement' => ['required', 'string', 'max:255'],
            'document_id' => ['nullable', 'integer', new BelongsToActiveCompany(Document::class)],
            'renewal_due_at' => ['nullable', 'date'],
            'completed' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['requirement' => 'requisito', 'document_id' => 'documento', 'renewal_due_at' => 'vencimiento', 'completed' => 'cumple', 'notes' => 'notas'];
    }
}
