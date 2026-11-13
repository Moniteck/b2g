<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteItem extends Model
{
    use HasFactory;

    protected $fillable = ['quote_id', 'description', 'quantity', 'unit', 'unit_cost', 'margin', 'unit_price', 'total', 'tax_rate', 'pricing_source', 'inventory_item_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_cost' => 'decimal:2', 'margin' => 'decimal:2', 'unit_price' => 'decimal:2', 'total' => 'decimal:2', 'tax_rate' => 'decimal:2'];
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
