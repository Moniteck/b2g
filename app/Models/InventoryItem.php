<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'blessing_id', 'sku', 'description', 'category', 'brand', 'unit',
        'stock', 'stock_available', 'cost', 'price', 'suggested_price', 'tax_rate',
        'tax_included', 'synced_at', 'raw', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'decimal:2',
            'stock_available' => 'decimal:2',
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'suggested_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_included' => 'boolean',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    /**
     * Precio de venta sugerido por Blessing, con impuestos incluidos.
     */
    public function salePrice(): float
    {
        return (float) ($this->suggested_price ?? $this->price);
    }

    /**
     * Desglose del impuesto contenido en el precio final.
     *
     * @return array{base: float, tax: float, total: float}
     */
    public function taxBreakdown(float $quantity = 1): array
    {
        $total = round($this->salePrice() * $quantity, 2);
        $rate = (float) ($this->tax_rate ?? 0);

        if ($rate <= 0 || $this->tax_included === false) {
            return ['base' => $total, 'tax' => 0.0, 'total' => $total];
        }

        $base = round($total / (1 + ($rate / 100)), 2);

        return ['base' => $base, 'tax' => round($total - $base, 2), 'total' => $total];
    }
}
