<?php

namespace App\Http\Requests\Contracts;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use App\Services\Contracts\ContractStatus;
use App\Services\Tenancy\CompanyContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición del contrato. El estado inicial solo se elige al crear; después se cambia con
 * «Cambiar estado» (`ContractStatusRequest`), que aplica las transiciones.
 */
class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $contract instanceof Contract
            ? $this->user()->can('update', $contract)
            : $this->user()->can('create', Contract::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['duration_business_days', 'is_end_date_manual', 'product_delivered'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([$flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false]);
            }
        }

        foreach (['number', 'name'] as $text) {
            if (is_string($this->input($text))) {
                $this->merge([$text => trim($this->input($text))]);
            }
        }
    }

    public function rules(): array
    {
        $contract = $this->route('contract');
        $companyId = $contract instanceof Contract
            ? $contract->company_id
            : ($this->input('company_id') ?? app(CompanyContext::class)->currentCompanyId());

        return [
            'company_id' => ['nullable', 'integer', new AllowedCompany('create')],
            'client_id' => ['nullable', 'integer', new BelongsToActiveCompany(Client::class)],
            'tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
            'quote_id' => ['nullable', 'integer', new BelongsToActiveCompany(Quote::class)],
            'opportunity_id' => ['nullable', 'integer', new BelongsToActiveCompany(Opportunity::class)],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id', $this->contactOfInstitution()],
            'manager_id' => ['nullable', 'integer', new BelongsToActiveCompany(User::class)],
            'number' => ['required', 'string', 'max:100', Rule::unique('contracts', 'number')
                ->where('company_id', $companyId)
                ->ignore($contract instanceof Contract ? $contract->id : null)],
            'name' => ['required', 'string', 'max:255'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'amount' => ['nullable', 'required_without:awarded_amount', 'numeric', 'min:0', 'max:999999999999'],
            'status' => [$contract instanceof Contract ? 'exclude' : 'nullable', Rule::in([ContractStatus::PENDING, ContractStatus::IN_PROGRESS, ContractStatus::SUSPENDED])],
            'signed_at' => ['nullable', 'date'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'duration_business_days' => ['nullable', 'boolean'],
            'start_trigger' => ['nullable', 'string', Rule::in(Contract::START_TRIGGERS)],
            'start_date' => ['nullable', 'date'],
            'is_end_date_manual' => ['nullable', 'boolean'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', Rule::requiredIf(fn () => (bool) $this->input('is_end_date_manual'))],
            'product_delivered' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'number.unique' => 'Ya existe un contrato con ese número en la empresa.',
            'amount.required_without' => 'Indique el monto adjudicado o el monto del contrato.',
            'end_date.after_or_equal' => 'La fecha fin no puede ser anterior a la fecha de inicio.',
            'end_date.required' => 'Indique la fecha fin si la fija manualmente.',
            'status.in' => 'Un contrato nuevo empieza en Pendiente de contrato, En ejecución o Suspendido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'company_id' => 'empresa', 'client_id' => 'cliente', 'tender_id' => 'licitación', 'quote_id' => 'cotización',
            'opportunity_id' => 'oportunidad', 'institution_id' => 'institución', 'contact_id' => 'persona responsable del contacto',
            'manager_id' => 'oficial de seguimiento', 'number' => 'número de contrato', 'name' => 'nombre',
            'awarded_amount' => 'monto adjudicado', 'amount' => 'monto del contrato', 'status' => 'estado',
            'signed_at' => 'fecha de firma', 'duration_days' => 'plazo', 'start_trigger' => 'hito de inicio',
            'start_date' => 'fecha de inicio', 'end_date' => 'fecha fin', 'notes' => 'notas',
        ];
    }

    /** El contacto debe ser de la institución elegida. */
    private function contactOfInstitution(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $institutionId = $this->input('institution_id');
            if ($value && $institutionId && ! Contact::query()->whereKey($value)->where(fn ($query) => $query->whereNull('institution_id')->orWhere('institution_id', $institutionId))->exists()) {
                $fail('La persona de contacto no pertenece a la institución elegida.');
            }
        };
    }
}
