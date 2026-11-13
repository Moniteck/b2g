<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractFollowUp extends Model
{
    use HasFactory;

    protected $fillable = ['contract_id', 'user_id', 'type', 'description', 'occurred_at', 'next_action', 'next_action_at'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'next_action_at' => 'date',
        ];
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
