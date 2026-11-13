<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ManualCashIncome extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'concept', 'amount', 'received_at', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'date'];
    }
}
