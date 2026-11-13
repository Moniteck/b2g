<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryAlarm extends Model
{
    protected $fillable = ['quote_id', 'created_by', 'idempotency_key', 'status', 'requested_at', 'completed_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }
}
