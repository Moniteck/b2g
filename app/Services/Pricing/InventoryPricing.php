<?php

namespace App\Services\Pricing;

use App\Models\InventoryItem;

/**
 * Puente entre un producto del inventario y el calculador único. Cotizaciones,
 * calculadora de oferta, pantalla de inventario y MCP deben obtener el precio
 * de un producto SOLO por aquí:
 *
 *   $line = app(InventoryPricing::class)->line($item, quantity: 12);
 *   $line->subtotal; $line->tax; $line->total; $line->unitPrice; // sin ISV
 */
class InventoryPricing
{
    public function __construct(private PriceCalculator $calculator) {}

    /** Precio sugerido del producto, en la base de su `tax_included`. */
    public function sourcePrice(InventoryItem $item): float
    {
        return (float) ($item->suggested_price ?? $item->price ?? 0);
    }

    public function taxRate(InventoryItem $item): float
    {
        return $item->tax_rate !== null ? (float) $item->tax_rate : TaxRules::DEFAULT_RATE;
    }

    public function line(
        InventoryItem $item,
        float $quantity = 1,
        float $discount = 0.0,
        float $discountPercent = 0.0,
        bool $exempt = false,
        ?string $currency = null,
    ): PriceLine {
        $currency ??= $item->currency ?: PriceCalculator::DEFAULT_CURRENCY;

        return $this->calculator->line(
            unitPrice: $this->sourcePrice($item),
            quantity: $quantity,
            taxRate: $this->taxRate($item),
            taxIncluded: (bool) $item->tax_included,
            discount: $discount,
            discountPercent: $discountPercent,
            exempt: $exempt,
            currency: $currency,
        );
    }
}
