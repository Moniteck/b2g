<?php

namespace App\Http\Requests\Calendar;

use App\Models\Event;
use App\Models\ReminderDelivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas de recordatorio de la empresa activa: una fila por tipo de plazo.
 */
class ReminderRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rules = collect((array) $this->input('rules'))->map(function ($rule) {
            if (! is_array($rule)) {
                return $rule;
            }

            $recipients = $rule['recipients'] ?? [];
            if (is_string($recipients)) {
                $recipients = preg_split('/[\s,;]+/', $recipients, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }

            return [...$rule, 'recipients' => array_values(array_map('trim', (array) $recipients)), 'is_active' => filter_var($rule['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)];
        })->all();

        $this->merge(['rules' => $rules]);
    }

    public function rules(): array
    {
        return [
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.event_type' => ['required', 'string', 'distinct', Rule::in(array_keys(Event::TYPES))],
            'rules.*.is_active' => ['boolean'],
            'rules.*.days_before' => ['present', 'array', 'max:10'],
            'rules.*.days_before.*' => ['integer', 'min:0', 'max:365', 'distinct'],
            'rules.*.send_time' => ['required', 'date_format:H:i'],
            'rules.*.channels' => ['present', 'array'],
            'rules.*.channels.*' => ['string', Rule::in(array_keys(ReminderDelivery::CHANNELS))],
            'rules.*.recipients' => ['present', 'array', 'max:10'],
            'rules.*.recipients.*' => ['email:filter', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'rules.*.days_before.*.distinct' => 'Hay anticipaciones repetidas.',
            'rules.*.days_before.*.max' => 'La anticipación máxima es de 365 días.',
            'rules.*.send_time.date_format' => 'La hora debe tener el formato HH:MM.',
            'rules.*.recipients.*.email' => 'Correo no válido: :input.',
        ];
    }
}
