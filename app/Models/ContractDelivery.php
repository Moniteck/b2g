<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id', 'description', 'quantity', 'unit', 'due_date', 'delivered_at',
        'status', 'evidence_document_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'due_date' => 'date',
            'delivered_at' => 'datetime',
        ];
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function evidence()
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }
}
