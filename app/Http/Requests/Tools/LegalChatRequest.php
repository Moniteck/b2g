<?php

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;

class LegalChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'conversation_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:4000'],
        ];
    }

    public function attributes(): array
    {
        return ['message' => 'consulta'];
    }
}
