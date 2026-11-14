<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OperationalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:255'], 'number' => ['sometimes', 'required', 'string', 'max:255'], 'concept' => ['sometimes', 'required', 'string', 'max:255'], 'amount' => ['sometimes', 'numeric', 'min:0'], 'status' => ['sometimes', 'string', 'max:100'], 'notes' => ['nullable', 'string']];
    }
}
