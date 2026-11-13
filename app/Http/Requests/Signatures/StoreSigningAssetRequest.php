<?php

namespace App\Http\Requests\Signatures;

use App\Models\SigningAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Subir o reemplazar la firma o el sello propios: PNG o JPG de hasta 2 MB.
 */
class StoreSigningAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(SigningAsset::TYPES))],
            'file' => [
                'required', 'file', 'mimes:png,jpg,jpeg', 'mimetypes:image/png,image/jpeg',
                'max:'.SigningAsset::MAX_KILOBYTES, 'dimensions:min_width=20,min_height=20,max_width=6000,max_height=6000',
            ],
        ];
    }

    public function attributes(): array
    {
        return ['type' => 'tipo', 'file' => 'imagen'];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Elige la imagen.',
            'file.mimes' => 'La imagen debe ser PNG o JPG.',
            'file.mimetypes' => 'La imagen debe ser PNG o JPG.',
            'file.max' => 'La imagen supera el máximo de 2 MB.',
            'file.dimensions' => 'La imagen debe medir entre 20 y 6000 píxeles por lado.',
        ];
    }
}
