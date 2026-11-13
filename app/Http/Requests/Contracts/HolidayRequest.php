<?php

namespace App\Http\Requests\Contracts;

use App\Models\Contract;
use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageHolidays', Contract::class);
    }

    public function rules(): array
    {
        $holiday = $this->route('holiday');

        return [
            'holiday_date' => ['required', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31',
                Rule::unique('holidays', 'holiday_date')
                    ->where('company_id', $holiday instanceof Holiday ? $holiday->company_id : app(\App\Services\Tenancy\CompanyContext::class)->currentCompanyId())
                    ->whereNull('deleted_at')
                    ->ignore($holiday instanceof Holiday ? $holiday->id : null)],
            'name' => ['required', 'string', 'max:150'],
            'is_pending_review' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['holiday_date.unique' => 'Ya hay un feriado registrado en esa fecha.'];
    }

    public function attributes(): array
    {
        return ['holiday_date' => 'fecha', 'name' => 'nombre'];
    }
}
