<?php

namespace App\Services\Pricing;

/**
 * Totales de un conjunto de líneas (ver PriceCalculator::document()).
 *
 * Los totales del documento son la suma exacta de las líneas ya redondeadas:
 * nunca se recalcula el impuesto sobre el subtotal global, así que el documento
 * cuadra siempre al centavo con el detalle.
 */
final class PriceDocument
{
    /**
     * @param  array<int, PriceLine>  $lines
     * @param  array<int, array{rate: float, base: float, tax: float}>  $taxes  Desglose por tasa aplicada, de menor a mayor.
     */
    public function __construct(
        public readonly string $currency,
        public readonly array $lines,
        public readonly float $gross,
        public readonly float $discount,
        public readonly float $subtotal,
        public readonly float $tax,
        public readonly float $total,
        public readonly array $taxes,
        /** Base de las líneas exentas o exoneradas (tasa 0). */
        public readonly float $exemptBase,
        /** Base de las líneas gravadas. */
        public readonly float $taxedBase,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withLines = true): array
    {
        $data = [
            'currency' => $this->currency,
            'gross' => $this->gross,
            'discount' => $this->discount,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'total' => $this->total,
            'taxes' => $this->taxes,
            'exempt_base' => $this->exemptBase,
            'taxed_base' => $this->taxedBase,
            'line_count' => count($this->lines),
        ];

        if ($withLines) {
            $data['lines'] = array_map(fn (PriceLine $line) => $line->toArray(), $this->lines);
        }

        return $data;
    }
}
