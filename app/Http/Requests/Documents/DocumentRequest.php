<?php

namespace App\Http\Requests\Documents;

use App\Services\Documents\UploadInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Base de las peticiones de documentos: la autorización la hace el controlador con la policy,
 * y un archivo que no llegó completo al servidor (límite de PHP, corte de red) se rechaza con
 * un mensaje claro antes de validar el resto.
 */
abstract class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $errors = [];

        foreach ($this->allFiles() as $field => $value) {
            foreach (is_array($value) ? $value : [$value] as $index => $file) {
                if ($file instanceof UploadedFile && ! $file->isValid()) {
                    $errors[is_array($value) ? "{$field}.{$index}" : $field] = $this->uploadError($file);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function uploadError(UploadedFile $file): string
    {
        $name = app(UploadInspector::class)->cleanName($file->getClientOriginalName());

        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "«{$name}» supera el tamaño que acepta el servidor (".ini_get('upload_max_filesize').'). Pide al administrador que suba el límite de PHP.',
            UPLOAD_ERR_PARTIAL => "«{$name}» llegó incompleto. Vuelve a subirlo.",
            default => "No se pudo recibir «{$name}». Vuelve a intentarlo.",
        };
    }

    public function attributes(): array
    {
        return [
            'file' => 'archivo',
            'files' => 'archivos',
            'files.*' => 'archivo',
            'name' => 'nombre',
            'category' => 'categoría',
            'reference_number' => 'número o referencia',
            'description' => 'descripción',
            'owner_name' => 'titular',
            'issued_at' => 'fecha de emisión',
            'expires_at' => 'fecha de vencimiento',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Elige un archivo.',
            'expires_at.after_or_equal' => 'El vencimiento no puede ser anterior a la emisión.',
            'category.required' => 'Elige la categoría del documento.',
            'category.in' => 'Elige una categoría de la lista.',
        ];
    }
}
