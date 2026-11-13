<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cambio de costo, precio sugerido o tasa de un producto, registrado por la sincronización.
 */
class InventoryPriceHistory extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'inventory_item_id', 'cost', 'suggested_price', 'tax_rate', 'tax_included', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'suggested_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_included' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
