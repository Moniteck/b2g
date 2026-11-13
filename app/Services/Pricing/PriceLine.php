<?php

namespace App\Services\Pricing;

/**
 * Resultado del cálculo de una línea (ver PriceCalculator::line()).
 *
 * Todos los importes van redondeados a 2 decimales. `subtotal` es siempre la
 * base SIN impuesto y `total = subtotal + tax`, exacto al centavo.
 */
final class PriceLine
{
    public function __construct(
        public readonly string $currency,
        public readonly float $quantity,
        /** Precio unitario sin impuesto. */
        public readonly float $unitPrice,
        /** Impuesto de una unidad (informativo; el impuesto de la línea se calcula sobre el subtotal). */
        public readonly float $unitTax,
        /** Precio unitario con impuesto. */
        public readonly float $unitTotal,
        /** Precio unitario tal como vino del origen (con o sin impuesto, según `taxIncluded`). */
        public readonly float $sourceUnitPrice,
        /** Importe de la línea antes del descuento, en la base del origen. */
        public readonly float $gross,
        public readonly float $discount,
        public readonly float $subtotal,
        /** Tasa aplicada (0 si la línea está exonerada). */
        public readonly float $taxRate,
        /** Tasa nominal del producto, aunque esté exonerado. */
        public readonly float $nominalRate,
        public readonly float $tax,
        public readonly float $total,
        public readonly bool $taxIncluded,
        public readonly bool $exempt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'unit_tax' => $this->unitTax,
            'unit_total' => $this->unitTotal,
            'source_unit_price' => $this->sourceUnitPrice,
            'gross' => $this->gross,
            'discount' => $this->discount,
            'subtotal' => $this->subtotal,
            'tax_rate' => $this->taxRate,
            'nominal_rate' => $this->nominalRate,
            'tax' => $this->tax,
            'total' => $this->total,
            'tax_included' => $this->taxIncluded,
            'exempt' => $this->exempt,
        ];
    }
}
