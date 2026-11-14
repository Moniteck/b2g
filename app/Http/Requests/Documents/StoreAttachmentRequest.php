<?php

namespace App\Http\Requests\Documents;

use Illuminate\Http\UploadedFile;

/**
 * Adjuntar uno o varios archivos a una entidad: `file` (uno) o `files[]` (varios), con
 * `category` y `description` comunes a todos.
 */
class StoreAttachmentRequest extends DocumentRequest
{
    /** Máximo de archivos por petición. */
    public const MAX_FILES = 20;

    public function rules(): array
    {
        return [
            'file' => ['required_without:files'],
            'files' => ['required_without:file', 'array', 'max:'.self::MAX_FILES],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required_without' => 'Elige al menos un archivo.',
            'files.required_without' => 'Elige al menos un archivo.',
            'files.max' => 'Sube como máximo '.self::MAX_FILES.' archivos a la vez.',
        ] + parent::messages();
    }

    /**
     * Archivos recibidos con el nombre del campo de cada uno (para el error bajo su archivo).
     *
     * @return array<string, UploadedFile>
     */
    public function uploadedFiles(): array
    {
        $files = [];

        if (($single = $this->file('file')) instanceof UploadedFile) {
            $files['file'] = $single;
        }

        foreach ((array) ($this->file('files') ?? []) as $index => $file) {
            if ($file instanceof UploadedFile) {
                $files["files.{$index}"] = $file;
            }
        }

        return $files;
    }
}
