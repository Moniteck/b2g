<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Pricing\InventoryPricing;
use App\Services\Pricing\PriceLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto del inventario de Blessing, por empresa. Lo escribe solo la
 * sincronización (App\Services\Blessing\InventorySyncService).
 *
 * El precio de un producto se calcula SIEMPRE con App\Services\Pricing\InventoryPricing
 * (o con `pricing()` de este modelo), que aplica la regla de impuesto única.
 */
class InventoryItem extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'blessing_id', 'sku', 'description', 'category', 'brand', 'unit',
        'stock', 'stock_available', 'cost', 'suggested_price', 'tax_rate',
        'tax_included', 'currency', 'synced_at', 'raw', 'is_active', 'is_demo', 'deactivated_at',
    ];

    protected $hidden = ['raw'];

    protected function casts(): array
    {
        return [
            'stock' => 'decimal:2',
            'stock_available' => 'decimal:2',
            'cost' => 'decimal:2',
            'suggested_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_included' => 'boolean',
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
            'synced_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(InventoryPriceHistory::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * Busca por SKU, descripción o marca.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn ($q) => $q
            ->where('sku', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->orWhere('brand', 'like', $like));
    }

    /** Existencia disponible para vender (la disponible si Blessing la informa). */
    public function availableStock(): float
    {
        return (float) ($this->stock_available ?? $this->stock ?? 0);
    }

    /** Subtotal, ISV y total de `$quantity` unidades con la regla única. */
    public function pricing(float $quantity = 1): PriceLine
    {
        return app(InventoryPricing::class)->line($this, $quantity);
    }
}
