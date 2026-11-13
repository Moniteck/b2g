<?php

namespace App\Http\Requests\Tenders;

use App\Services\Tenders\BidReviewService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmación de la revisión del pliego: qué datos aplicar y con qué valor.
 *
 *   fields[clarification_deadline][apply] = true
 *   fields[clarification_deadline][value] = 2026-09-17T23:59
 *   lists[documents] = true    (documentos exigidos → licitación)
 *   lists[criteria]  = true    (criterios de evaluación → requisitos)
 */
class BidReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'fields' => ['array'],
            'lists' => ['array'],
            'lists.documents' => ['boolean'],
            'lists.criteria' => ['boolean'],
        ];

        foreach (BidReviewService::FIELD_MAP as $key => [, , $type]) {
            $rules["fields.{$key}.apply"] = ['boolean'];
            $rules["fields.{$key}.value"] = array_merge(["required_if_accepted:fields.{$key}.apply", 'nullable'], match ($type) {
                'datetime' => ['date'],
                'integer' => ['integer', 'min:1', 'max:3650'],
                default => ['string', 'max:'.($key === 'object' ? 1000 : 255)],
            });
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'fields.*.value.required_if_accepted' => 'Escribe el valor o desmarca el dato.',
            'fields.*.value.date' => 'La fecha no es válida.',
            'fields.*.value.integer' => 'Debe ser un número entero de días.',
        ];
    }
}
