<?php

namespace App\Http\Requests\Tools;

use App\Support\Tools\DocumentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LegalWritingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(DocumentTypes::LEGAL))],
            'company' => ['required', 'string', 'max:255'],
            'recipient' => ['required', 'string', 'max:255'],
            'process' => ['nullable', 'string', 'max:255'],
            'context' => ['required', 'string', 'min:20', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return ['type' => 'tipo de escrito', 'company' => 'empresa proveedora', 'recipient' => 'institución destinataria', 'process' => 'proceso o contrato', 'context' => 'hechos y solicitud'];
    }
}
