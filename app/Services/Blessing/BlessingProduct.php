<?php

namespace App\Services\Blessing;

use Carbon\CarbonImmutable;

/**
 * Producto tal como lo entrega Blessing, ya normalizado por el cliente.
 * `taxIncluded` y `taxRate` son null cuando la API no los informa: entonces
 * decide App\Services\Pricing\TaxRules.
 */
final class BlessingProduct
{
    public function __construct(
        public readonly string $id,
        public readonly string $sku,
        public readonly string $description,
        public readonly ?string $category = null,
        public readonly ?string $brand = null,
        public readonly ?string $unit = null,
        public readonly ?float $stock = null,
        public readonly ?float $stockAvailable = null,
        public readonly ?float $cost = null,
        public readonly ?float $suggestedPrice = null,
        public readonly ?float $taxRate = null,
        public readonly ?bool $taxIncluded = null,
        public readonly bool $active = true,
        public readonly ?CarbonImmutable $updatedAt = null,
        public readonly string $currency = 'HNL',
        /** @var array<string, mixed> Registro original de la API (se guarda en `raw`). */
        public readonly array $raw = [],
    ) {}

    /**
     * Problemas que impiden guardar el producto (vacío si es válido).
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $problems = [];

        if (trim($this->id) === '') {
            $problems[] = 'sin identificador de Blessing';
        }

        if (trim($this->sku) === '') {
            $problems[] = 'sin SKU';
        } elseif (mb_strlen($this->sku) > 255) {
            $problems[] = 'SKU demasiado largo';
        }

        if (trim($this->description) === '') {
            $problems[] = 'sin descripción';
        }

        foreach (['cost' => 'costo', 'suggestedPrice' => 'precio sugerido'] as $field => $label) {
            if ($this->{$field} !== null && $this->{$field} < 0) {
                $problems[] = "{$label} negativo";
            }
        }

        if ($this->taxRate !== null && ($this->taxRate < 0 || $this->taxRate > 100)) {
            $problems[] = 'tasa de impuesto fuera de rango';
        }

        if (! in_array($this->currency, ['HNL', 'USD'], true)) {
            $problems[] = "moneda no admitida ({$this->currency})";
        }

        return $problems;
    }
}
