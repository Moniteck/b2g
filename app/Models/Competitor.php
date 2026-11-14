<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Competitor extends Model
{
    protected $fillable = ['name', 'tax_id', 'category', 'wins', 'losses', 'institutions', 'average_award_price', 'last_participation_at', 'notes'];

    protected function casts(): array
    {
        return ['institutions' => 'array', 'average_award_price' => 'decimal:2', 'last_participation_at' => 'date'];
    }
}
