<?php

namespace App\Http\Requests\Quotes;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteStatusRequest extends FormRequest
{
    use AuthorizesSubject;

    protected function abilities(): array
    {
        return ['quote' => 'changeStatus'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(Quote::STATUSES)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['status.required' => 'Elija el nuevo estado.', 'status.in' => 'El estado elegido no es válido.'];
    }
}
