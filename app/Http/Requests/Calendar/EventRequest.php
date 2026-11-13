<?php

namespace App\Http\Requests\Calendar;

use App\Models\Event;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Evento manual del calendario. La autorización la hace el controlador.
 */
class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'all_day' => $this->boolean('all_day'),
            'custom_reminders' => $this->boolean('custom_reminders'),
            'starts_at' => $this->normalizeDate($this->input('starts_at')),
            'ends_at' => $this->normalizeDate($this->input('ends_at')),
        ]);
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->currentCompanyId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', Rule::in(array_keys(Event::TYPES))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'custom_reminders' => ['boolean'],
            'reminders' => ['nullable', 'array', 'max:10'],
            'reminders.*' => ['integer', 'min:0', 'max:365', 'distinct'],
            'recipients' => ['nullable', 'array', 'max:30'],
            'recipients.*' => ['integer', 'distinct', function (string $attribute, mixed $value, \Closure $fail) use ($companyId) {
                $member = DB::table('company_user')->where('company_id', $companyId)->where('user_id', (int) $value)->exists();
                if (! $member) {
                    $fail('El destinatario elegido no pertenece a la empresa activa.');
                }
            }],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'título',
            'type' => 'tipo',
            'starts_at' => 'inicio',
            'ends_at' => 'fin',
            'description' => 'descripción',
            'reminders' => 'recordatorios',
            'reminders.*' => 'anticipación',
            'recipients' => 'destinatarios',
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.after_or_equal' => 'El fin no puede ser anterior al inicio.',
            'reminders.*.distinct' => 'Hay anticipaciones repetidas.',
            'reminders.*.max' => 'La anticipación máxima es de 365 días.',
        ];
    }

    protected function normalizeDate(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value === '' ? null : $value;
        }

        return str_replace('T', ' ', $value);
    }
}
