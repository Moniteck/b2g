<?php

namespace App\Http\Requests\Tools;

use App\Models\Document;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;

class ComplianceRenewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_id' => ['required', 'integer', new BelongsToActiveCompany(Document::class)],
            'renewal_due_at' => ['required', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['document_id' => 'documento renovado', 'renewal_due_at' => 'nuevo vencimiento', 'notes' => 'notas'];
    }

    public function messages(): array
    {
        return ['renewal_due_at.after' => 'El nuevo vencimiento debe ser una fecha futura.'];
    }
}
