<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompetitorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('competitors', 'name')->ignore($this->route('competitor'))],
            'tax_id' => ['nullable', 'string', 'max:100', Rule::unique('competitors', 'tax_id')->ignore($this->route('competitor'))],
            'category' => ['nullable', 'string', 'max:100'],
            'wins' => ['required', 'integer', 'min:0'],
            'losses' => ['required', 'integer', 'min:0'],
            'institutions' => ['nullable', 'array'],
            'institutions.*' => ['string', 'max:255'],
            'average_award_price' => ['nullable', 'numeric', 'min:0'],
            'last_participation_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
