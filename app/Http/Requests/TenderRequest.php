<?php

namespace App\Http\Requests;

use App\Rules\AllowedCompany;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', new AllowedCompany('create')],
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'reference' => ['required', 'string', 'max:100', Rule::unique('tenders', 'reference')->where('company_id', $this->input('company_id') ?: app(CompanyContext::class)->currentCompanyId())->ignore($this->route('tender'))],
            'process_name' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'purchase_type' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:Activa,En preparación,Presentada,Adjudicada,No adjudicada,Perdida,Cancelada'],
            'estimated_amount' => ['required', 'numeric', 'min:0'],
            'published_at' => ['nullable', 'date'],
            'clarification_deadline' => ['nullable', 'date'],
            'presentation_deadline' => ['nullable', 'date'],
            'opening_at' => ['nullable', 'date'],
            'closing_date' => ['nullable', 'date', 'after_or_equal:published_at'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'delivery_terms' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'offer_submitted' => ['boolean'],
            'result' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
