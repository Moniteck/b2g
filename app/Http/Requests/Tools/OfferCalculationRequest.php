<?php

namespace App\Http\Requests\Tools;

use App\Models\InventoryItem;
use App\Rules\BelongsToActiveCompany;
use App\Services\Pricing\PriceCalculator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la calculadora de oferta. Conserva `cost`, `margin` y `tax_rate`, que son
 * la base del cálculo.
 */
class OfferCalculationRequest extends FormRequest
{
    public const DEFAULT_SCENARIOS = [10, 15, 20, 25, 30];

    public function authorize(): bool
    {
        return $this->user()?->can('price', InventoryItem::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))
            ->map(fn ($line) => is_array($line) ? $line + ['exempt' => false] : $line)
            ->all();

        $this->merge([
            'currency' => $this->input('currency') ?: PriceCalculator::DEFAULT_CURRENCY,
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:200'],
            'currency' => ['required', 'in:'.implode(',', PriceCalculator::CURRENCIES)],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.inventory_item_id' => ['nullable', 'integer', new BelongsToActiveCompany(InventoryItem::class)],
            'lines.*.sku' => ['nullable', 'string', 'max:255'],
            'lines.*.unit' => ['nullable', 'string', 'max:50'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'lines.*.cost' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'lines.*.margin' => ['required', 'numeric', 'min:0', 'lt:100'],
            'lines.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.exempt' => ['boolean'],
            'scenarios' => ['nullable', 'array', 'max:6'],
            'scenarios.*' => ['numeric', 'min:0', 'lt:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'lines' => 'líneas',
            'lines.*.description' => 'descripción',
            'lines.*.quantity' => 'cantidad',
            'lines.*.cost' => 'costo',
            'lines.*.margin' => 'margen',
            'lines.*.tax_rate' => 'tasa de impuesto',
            'scenarios.*' => 'margen del escenario',
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Agrega al menos una línea.',
            'lines.min' => 'Agrega al menos una línea.',
            'lines.*.margin.lt' => 'El margen sobre la venta debe ser menor que 100 %.',
            'scenarios.*.lt' => 'El margen de cada escenario debe ser menor que 100 %.',
        ];
    }

    /**
     * @return array<int, float>
     */
    public function scenarios(): array
    {
        $values = $this->validated('scenarios') ?: self::DEFAULT_SCENARIOS;

        return collect($values)->map(fn ($value) => (float) $value)->unique()->sort()->values()->all();
    }
}
