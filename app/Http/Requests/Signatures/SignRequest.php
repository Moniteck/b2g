<?php

namespace App\Http\Requests\Signatures;

use App\Models\Document;
use App\Rules\BelongsToActiveCompany;
use App\Support\Binaries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Firmar un documento del sistema (`document_id`) o un PDF subido suelto (`file`).
 *
 * `placements`: [{ type: firma|sello, page, x, y, width, height }] en porcentaje de la
 * página, con origen arriba a la izquierda. Puede llegar como lista o como JSON
 * (las subidas multipart lo envían como texto).
 */
class SignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $placements = $this->input('placements');

        if (is_string($placements)) {
            $decoded = json_decode($placements, true);
            $this->merge(['placements' => is_array($decoded) ? $decoded : null]);
        }

        foreach (['use_signature', 'use_seal', 'all_pages', 'confirm', 'save_to_documents'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([$flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN)]);
            }
        }
    }

    public function rules(): array
    {
        $upload = $this->routeIs('signatures.sign-upload');

        return [
            'document_id' => $upload ? ['prohibited'] : ['required', 'integer', new BelongsToActiveCompany(Document::class)],
            'file' => $upload ? ['required', 'file', 'mimes:pdf', 'max:'.min(Binaries::maxPdfKilobytes(), 25 * 1024)] : ['prohibited'],
            'save_to_documents' => ['sometimes', 'boolean'],
            'document_name' => ['nullable', 'string', 'max:255'],
            'use_signature' => ['required', 'boolean'],
            'use_seal' => ['required', 'boolean'],
            'all_pages' => ['sometimes', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
            'placements' => ['required', 'array', 'min:1', 'max:2'],
            'placements.*.type' => ['required', 'string', 'in:firma,sello', 'distinct'],
            'placements.*.page' => ['required', 'integer', 'min:1', 'max:10000'],
            'placements.*.x' => ['required', 'numeric', 'min:0', 'max:100'],
            'placements.*.y' => ['required', 'numeric', 'min:0', 'max:100'],
            'placements.*.width' => ['required', 'numeric', 'min:0.5', 'max:100'],
            'placements.*.height' => ['required', 'numeric', 'min:0.5', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->boolean('use_signature') && ! $this->boolean('use_seal')) {
                $validator->errors()->add('use_signature', 'Elige firma, sello o ambos.');

                return;
            }

            foreach ((array) $this->input('placements', []) as $index => $placement) {
                $type = $placement['type'] ?? null;

                if (($type === 'firma' && ! $this->boolean('use_signature')) || ($type === 'sello' && ! $this->boolean('use_seal'))) {
                    continue;
                }

                if ((float) ($placement['x'] ?? 0) + (float) ($placement['width'] ?? 0) > 100.5
                    || (float) ($placement['y'] ?? 0) + (float) ($placement['height'] ?? 0) > 100.5) {
                    $validator->errors()->add("placements.{$index}", 'La firma o el sello se sale de la página.');
                }
            }

            $types = collect((array) $this->input('placements', []))->pluck('type');

            if ($this->boolean('use_signature') && ! $types->contains('firma')) {
                $validator->errors()->add('placements', 'Falta colocar la firma.');
            }

            if ($this->boolean('use_seal') && ! $types->contains('sello')) {
                $validator->errors()->add('placements', 'Falta colocar el sello.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'document_id' => 'documento',
            'file' => 'PDF',
            'placements' => 'posiciones',
            'use_signature' => 'firma',
            'use_seal' => 'sello',
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'El archivo debe ser un PDF.',
            'file.max' => 'El PDF supera el tamaño máximo de :max KB.',
            'document_id.required' => 'Elige el documento que vas a firmar.',
        ];
    }

    /**
     * Opciones para `SignatureService`.
     *
     * @return array{use_signature: bool, use_seal: bool, placements: array<int, array<string, mixed>>, all_pages: bool, confirm: bool}
     */
    public function options(): array
    {
        $useSignature = $this->boolean('use_signature');
        $useSeal = $this->boolean('use_seal');

        return [
            'use_signature' => $useSignature,
            'use_seal' => $useSeal,
            'placements' => array_values(array_filter(
                (array) $this->validated('placements'),
                fn (array $placement) => ($placement['type'] === 'firma' && $useSignature) || ($placement['type'] === 'sello' && $useSeal),
            )),
            'all_pages' => $this->boolean('all_pages'),
            'confirm' => $this->boolean('confirm'),
        ];
    }
}
