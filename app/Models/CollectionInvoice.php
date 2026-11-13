<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CollectionInvoice extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'client_id', 'institution_id', 'number', 'concept', 'amount', 'issued_at', 'due_at', 'paid_at', 'status', 'interest_amount', 'interest_rate', 'collection_note', 'payment_recorded_at', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'interest_amount' => 'decimal:2', 'interest_rate' => 'decimal:2', 'issued_at' => 'date', 'due_at' => 'date', 'paid_at' => 'date', 'payment_recorded_at' => 'datetime'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
