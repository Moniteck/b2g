<?php

namespace App\Http\Requests\Tools;

use App\Support\Tools\DocumentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(DocumentTypes::BUSINESS))],
            'recipient' => ['required', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
            'context' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return ['type' => 'tipo de documento', 'recipient' => 'destinatario', 'contact' => 'persona de contacto', 'context' => 'contenido'];
    }
}
