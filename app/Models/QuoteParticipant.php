<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id', 'name', 'tax_id', 'amount', 'currency', 'includes_tax', 'is_ours',
        'position', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'includes_tax' => 'boolean',
            'is_ours' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }
}
