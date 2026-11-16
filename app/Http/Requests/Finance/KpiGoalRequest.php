<?php

namespace App\Http\Requests\Finance;

use App\Models\KpiGoal;
use App\Services\Finance\KpiService;
use App\Services\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Meta de un KPI para un período. Una sola meta por métrica y período en la
 * empresa activa; al editar no puede chocar con otra existente.
 */
class KpiGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isPercent = in_array($this->input('metric'), KpiService::PERCENT_METRICS, true);

        return [
            'metric' => ['required', Rule::in(array_keys(KpiService::METRICS))],
            'target' => ['required', 'numeric', 'min:0', $isPercent ? 'max:100' : 'max:999999999999'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ];
    }

    public function attributes(): array
    {
        return ['metric' => 'indicador', 'target' => 'meta', 'period_start' => 'inicio del período', 'period_end' => 'fin del período'];
    }

    public function messages(): array
    {
        return [
            'period_end.after_or_equal' => 'El fin del período debe ser igual o posterior al inicio.',
            'target.max' => in_array($this->input('metric'), KpiService::PERCENT_METRICS, true)
                ? 'La meta de un porcentaje no puede pasar de 100.'
                : 'La meta es demasiado grande.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = CarbonImmutable::parse($this->input('period_start'));
            $end = CarbonImmutable::parse($this->input('period_end'));

            if ($start->diffInDays($end) > 366) {
                $validator->errors()->add('period_end', 'El período de una meta no puede pasar de un año.');

                return;
            }

            $goal = $this->route('kpiGoal');

            if ($goal instanceof KpiGoal) {
                $duplicate = KpiGoal::query()
                    ->where('company_id', app(CompanyContext::class)->currentCompanyId())
                    ->where('metric', $this->input('metric'))
                    ->whereDate('period_start', $start->toDateString())
                    ->whereDate('period_end', $end->toDateString())
                    ->whereKeyNot($goal->id)
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('metric', 'Ya hay una meta de este indicador para el mismo período.');
                }
            }
        }];
    }
}
