<?php

namespace App\Http\Requests\Pipeline;

use App\Models\Opportunity;
use App\Models\Tender;
use App\Models\User;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una oportunidad del pipeline.
 *
 * La empresa es siempre la activa (alta) o la de la oportunidad (edición). La etapa solo se elige al
 * crear (Prospecto u Oportunidad); después cambia moviendo la tarjeta (OpportunityStageService).
 * Se autoriza antes de validar: sin permiso, 403 y no errores de formulario.
 */
class OpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $opportunity = $this->route('opportunity');

        return $opportunity instanceof Opportunity
            ? (bool) $this->user()?->can('update', $opportunity)
            : (bool) $this->user()?->can('create', Opportunity::class);
    }

    protected function prepareForValidation(): void
    {
        $opportunity = $this->route('opportunity');

        if ($opportunity instanceof Opportunity) {
            // BelongsToActiveCompany valida los ids contra la empresa de la oportunidad.
            $this->merge(['company_id' => $opportunity->company_id]);
        }

        foreach (['institution_id', 'contact_id', 'tender_id', 'owner_id', 'expected_close_date', 'notes', 'next_action', 'opportunity_type'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $opportunity = $this->route('opportunity');
        $creating = ! $opportunity instanceof Opportunity;

        return [
            // Alta: la empresa activa o una en la que el usuario puede crear; edición: la de la oportunidad.
            'company_id' => [$creating ? 'nullable' : 'required', 'integer', new AllowedCompany($creating ? Permissions::CREATE : null)],
            'title' => ['required', 'string', 'max:255'],
            'institution_id' => ['required', 'integer', Rule::exists('institutions', 'id')],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('institution_id', $this->input('institution_id')))],
            'tender_id' => [
                'nullable', 'integer', new BelongsToActiveCompany(Tender::class),
                // Decisión 13: una licitación tiene una sola oportunidad.
                Rule::unique('opportunities', 'tender_id')->ignore($opportunity instanceof Opportunity ? $opportunity->id : null),
            ],
            'owner_id' => ['nullable', 'integer', new BelongsToActiveCompany(User::class)],
            'opportunity_type' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'probability' => ['required', 'integer', 'between:0,100'],
            'stage' => $creating ? ['nullable', Rule::in(Opportunity::CREATABLE_STAGES)] : ['prohibited'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'institution_id' => 'institución',
            'contact_id' => 'contacto',
            'tender_id' => 'licitación',
            'owner_id' => 'responsable',
            'opportunity_type' => 'tipo',
            'amount' => 'monto estimado',
            'probability' => 'probabilidad',
            'stage' => 'etapa',
            'next_action' => 'próxima acción',
            'expected_close_date' => 'cierre esperado',
            'notes' => 'notas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Escriba el título de la oportunidad.',
            'institution_id.required' => 'Elija la institución compradora.',
            'tender_id.unique' => 'Esa licitación ya tiene una oportunidad en el pipeline.',
            'stage.in' => 'Una oportunidad nueva empieza en Prospecto u Oportunidad.',
            'stage.prohibited' => 'La etapa se cambia moviendo la tarjeta en el tablero.',
            'contact_id.exists' => 'El contacto no pertenece a la institución elegida.',
        ];
    }

    /**
     * Datos listos para guardar (sin la etapa al editar).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return collect($this->validated())->except(['stage', 'company_id'])->all();
    }
}
