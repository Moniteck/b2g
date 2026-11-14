<?php

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RelationInteractionRequest extends FormRequest
{
    public const TYPES = ['Reunión', 'Llamada', 'Correo', 'Visita', 'Evento', 'Otro'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'description' => ['required', 'string', 'max:5000'],
            'occurred_at' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateTimeString()],
            'result' => ['nullable', 'string', 'max:255'],
            'next_action_at' => ['nullable', 'date', 'after_or_equal:'.today()->toDateString()],
        ];
    }

    public function attributes(): array
    {
        return ['type' => 'tipo', 'description' => 'descripción', 'occurred_at' => 'fecha', 'result' => 'resultado', 'next_action_at' => 'próxima acción'];
    }

    public function messages(): array
    {
        return [
            'occurred_at.before_or_equal' => 'La interacción no puede tener fecha futura.',
            'next_action_at.after_or_equal' => 'La próxima acción debe ser hoy o una fecha futura.',
        ];
    }
}
