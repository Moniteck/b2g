<?php

namespace App\Services\Pricing;

/**
 * Calculadora de oferta: de costo y margen sobre la venta a precio, ISV y total,
 * con comparación de escenarios de margen. Todo el cálculo lo hace PriceCalculator.
 * Los precios que salen de aquí NO incluyen impuesto (se suma la tasa de cada línea).
 */
class OfferCalculator
{
    public function __construct(private PriceCalculator $calculator) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines  [{ description, quantity, cost, margin, tax_rate, exempt?, unit?, sku?, inventory_item_id? }]
     * @param  array<int, float>  $scenarios  Márgenes (%) a comparar.
     * @return array<string, mixed>
     */
    public function calculate(array $lines, string $currency = PriceCalculator::DEFAULT_CURRENCY, array $scenarios = []): array
    {
        $priced = [];
        $costCents = 0;

        foreach ($lines as $line) {
            $quantity = (float) $line['quantity'];
            $cost = (float) $line['cost'];
            $margin = (float) $line['margin'];
            $lineCost = PriceCalculator::round($cost * $quantity);
            $costCents += (int) round($lineCost * 100);

            $result = $this->priceLine($line, $margin, $currency);

            $priced[] = [
                'line' => $result,
                'data' => [
                    'description' => (string) $line['description'],
                    'sku' => $line['sku'] ?? null,
                    'unit' => $line['unit'] ?? null,
                    'inventory_item_id' => $line['inventory_item_id'] ?? null,
                    'cost' => PriceCalculator::round($cost),
                    'margin' => $margin,
                    'cost_total' => $lineCost,
                    'profit' => PriceCalculator::round($result->subtotal - $lineCost),
                ] + $result->toArray(),
            ];
        }

        $document = $this->calculator->document(array_column($priced, 'line'), $currency);
        $costTotal = $costCents / 100.0;
        $profit = PriceCalculator::round($document->subtotal - $costTotal);

        return [
            'lines' => array_column($priced, 'data'),
            'totals' => $document->toArray(false) + [
                'cost_total' => $costTotal,
                'profit' => $profit,
                'margin' => $this->calculator->marginOf($costTotal, $document->subtotal),
            ],
            'scenarios' => array_map(fn (float $margin) => $this->scenario($lines, $margin, $currency, $costTotal), $scenarios),
        ];
    }

    /**
     * Mismas líneas con un margen uniforme.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, float>
     */
    private function scenario(array $lines, float $margin, string $currency, float $costTotal): array
    {
        $document = $this->calculator->document(
            array_map(fn (array $line) => $this->priceLine($line, $margin, $currency), $lines),
            $currency,
        );

        return [
            'margin' => $margin,
            'subtotal' => $document->subtotal,
            'tax' => $document->tax,
            'total' => $document->total,
            'profit' => PriceCalculator::round($document->subtotal - $costTotal),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function priceLine(array $line, float $margin, string $currency): PriceLine
    {
        return $this->calculator->line(
            unitPrice: $this->calculator->priceFromMargin((float) $line['cost'], $margin),
            quantity: (float) $line['quantity'],
            taxRate: (float) $line['tax_rate'],
            taxIncluded: false,
            exempt: (bool) ($line['exempt'] ?? false),
            currency: $currency,
        );
    }
}
