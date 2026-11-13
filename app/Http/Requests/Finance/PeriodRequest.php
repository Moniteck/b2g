<?php

namespace App\Http\Requests\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Filtro de período (desde / hasta) de reportes y KPI. Sin fechas, el año en
 * curso hasta hoy; el rango no puede pasar de tres años.
 */
class PeriodRequest extends FormRequest
{
    /** Módulos del reporte. */
    public const MODULES = ['all', 'pipeline', 'cotizaciones', 'cobros', 'licitaciones', 'poa'];

    private const MAX_DAYS = 1100;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'module' => ['nullable', 'in:'.implode(',', self::MODULES)],
        ];
    }

    public function attributes(): array
    {
        return ['from' => 'fecha inicial', 'to' => 'fecha final', 'module' => 'módulo'];
    }

    public function messages(): array
    {
        return ['to.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('from') || ! $this->filled('to')) {
                return;
            }

            if (CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > self::MAX_DAYS) {
                $validator->errors()->add('to', 'El período no puede pasar de tres años.');
            }
        }];
    }

    /**
     * @return array{from: string, to: string, module: string}
     */
    public function period(?string $defaultFrom = null, ?string $defaultTo = null): array
    {
        $data = $this->validated();

        return [
            'from' => $data['from'] ?? $defaultFrom ?? today()->startOfYear()->toDateString(),
            'to' => $data['to'] ?? $defaultTo ?? today()->toDateString(),
            'module' => $data['module'] ?? 'all',
        ];
    }
}
