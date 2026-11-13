<?php

namespace App\Http\Requests\Documents;

/** Reemplazar el archivo: crea una versión nueva y conserva la anterior. */
class ReplaceDocumentRequest extends DocumentRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required'],
        ];
    }
}
