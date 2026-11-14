<?php

namespace App\Http\Requests\Quotes;

use Illuminate\Foundation\Http\FormRequest;

class RevertRequest extends FormRequest
{
    use AuthorizesSubject;

    protected function abilities(): array
    {
        return ['quote' => 'revertOutcome', 'opportunity' => 'revertOutcome'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:2000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indique el motivo de la reversión.',
            'reason.min' => 'Explique el motivo con al menos 5 caracteres.',
        ];
    }
}
