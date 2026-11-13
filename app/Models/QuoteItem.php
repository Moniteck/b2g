<?php

namespace App\Models;

use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PriceLine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Línea de una cotización.
 *
 * `unit_price` es el precio tal como se capturó, en la base que indica `tax_included`
 * (con o sin ISV). `subtotal` es la base sin ISV, `tax` el impuesto de la línea y `total`
 * lo que paga el cliente; los tres los calcula PriceCalculator.
 */
class QuoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id', 'position', 'description', 'quantity', 'unit', 'unit_cost', 'margin', 'unit_price',
        'discount_percent', 'subtotal', 'tax', 'total', 'tax_rate', 'tax_included', 'pricing_source',
        'inventory_item_id', 'sku',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'margin' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_included' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    /** `inventory_item_id` es varchar en la tabla: se compara como entero. */
    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** Cálculo de la línea con la regla única (para el PDF y el formulario). */
    public function priced(): PriceLine
    {
        return app(PriceCalculator::class)->line(
            unitPrice: (float) $this->unit_price,
            quantity: max((float) $this->quantity, 0.01),
            taxRate: (float) ($this->tax_rate ?? 0),
            taxIncluded: (bool) $this->tax_included,
            discountPercent: (float) ($this->discount_percent ?? 0),
        );
    }
}
