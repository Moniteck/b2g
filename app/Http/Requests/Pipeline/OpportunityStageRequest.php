<?php

namespace App\Http\Requests\Pipeline;

use App\Models\Opportunity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mover una tarjeta del tablero. Las reglas de transición están en OpportunityStageService. */
class OpportunityStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $opportunity = $this->route('opportunity');

        return $opportunity instanceof Opportunity && (bool) $this->user()?->can('move', $opportunity);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['stage' => ['required', 'string', Rule::in(array_keys(Opportunity::STAGES))]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stage.required' => 'Elija la etapa de destino.',
            'stage.in' => 'La etapa elegida no existe.',
        ];
    }
}
