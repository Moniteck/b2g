<?php

namespace App\Http\Requests\Documents;

/** Renovar: nuevas fechas y, opcionalmente, el archivo renovado. */
class RenewDocumentRequest extends DocumentRequest
{
    public function rules(): array
    {
        return [
            'issued_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at', 'after_or_equal:today'],
            'file' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'issued_at.required' => 'Indica la nueva fecha de emisión.',
            'expires_at.after_or_equal' => 'El nuevo vencimiento no puede ser anterior a la emisión ni a hoy.',
        ] + parent::messages();
    }
}
