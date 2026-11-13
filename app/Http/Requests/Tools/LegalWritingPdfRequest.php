<?php

namespace App\Http\Requests\Tools;

use App\Support\Tools\DocumentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LegalWritingPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(DocumentTypes::LEGAL))],
            'content' => ['required', 'string', 'max:30000'],
        ];
    }

    public function attributes(): array
    {
        return ['type' => 'tipo de escrito', 'content' => 'contenido'];
    }
}
