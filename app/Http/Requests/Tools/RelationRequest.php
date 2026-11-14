<?php

namespace App\Http\Requests\Tools;

use App\Models\Contact;
use App\Models\Institution;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RelationRequest extends FormRequest
{
    public const STATUSES = ['Activa', 'En pausa', 'Inactiva'];

    public const INFLUENCE = ['Alta', 'Media', 'Baja'];

    public const TYPES = ['Tomador de decisiones', 'Influenciador', 'Técnico', 'Administrativo', 'Aliado', 'Otro'];

    public const CHANNELS = ['Reunión presencial', 'Llamada', 'Correo', 'WhatsApp', 'Evento', 'Otro'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'integer', new BelongsToActiveCompany(Institution::class)],
            'contact_id' => ['nullable', 'integer', new BelongsToActiveCompany(Contact::class)],
            'relationship_type' => ['nullable', 'string', Rule::in(self::TYPES)],
            'influence' => ['nullable', 'string', Rule::in(self::INFLUENCE)],
            'channel' => ['nullable', 'string', Rule::in(self::CHANNELS)],
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'started_at' => ['nullable', 'date'],
            'next_contact_at' => ['nullable', 'date'],
            'poa_interest' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $contactId = $this->input('contact_id');

            if (filled($contactId) && ! $validator->errors()->hasAny(['contact_id', 'institution_id'])
                && ! Contact::query()->whereKey($contactId)->where('institution_id', $this->input('institution_id'))->exists()) {
                $validator->errors()->add('contact_id', 'El contacto no pertenece a la institución elegida.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'institution_id' => 'institución', 'contact_id' => 'contacto', 'relationship_type' => 'tipo de relación',
            'influence' => 'influencia', 'channel' => 'canal', 'status' => 'estado', 'started_at' => 'inicio de la relación',
            'next_contact_at' => 'próxima acción', 'poa_interest' => 'interés en el POA', 'notes' => 'notas',
        ];
    }
}
