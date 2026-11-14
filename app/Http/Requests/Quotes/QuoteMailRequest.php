<?php

namespace App\Http\Requests\Quotes;

use Illuminate\Foundation\Http\FormRequest;

/** Envío de la cotización por correo: hasta 5 destinatarios con formato válido. */
class QuoteMailRequest extends FormRequest
{
    use AuthorizesSubject;

    public const MAX_RECIPIENTS = 5;

    protected function abilities(): array
    {
        return ['quote' => 'send'];
    }

    protected function prepareForValidation(): void
    {
        $recipients = $this->input('recipients');

        if (is_string($recipients)) {
            $recipients = preg_split('/[\s,;]+/', $recipients, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (is_array($recipients)) {
            $this->merge(['recipients' => array_values(array_unique(array_map(fn ($email) => mb_strtolower(trim((string) $email)), $recipients)))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipients' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'email:rfc,strict', 'max:255'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:3000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipients.required' => 'Escriba al menos un destinatario.',
            'recipients.max' => 'Puede enviar a un máximo de '.self::MAX_RECIPIENTS.' destinatarios.',
            'recipients.*.email' => 'El correo «:input» no es válido.',
        ];
    }
}
