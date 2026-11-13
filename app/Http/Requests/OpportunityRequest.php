<?php

namespace App\Http\Requests;

use App\Models\Opportunity;
use Illuminate\Foundation\Http\FormRequest;

class OpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'tender_id' => ['nullable', 'exists:tenders,id'],
            'opportunity_type' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0'],
            'probability' => ['required', 'integer', 'between:0,100'],
            'stage' => ['required', 'in:'.implode(',', array_keys(Opportunity::STAGES))],
            'next_action' => ['nullable', 'string', 'max:255'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
