<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySyncRun extends Model
{
    protected $fillable = ['company_id', 'status', 'started_at', 'finished_at', 'items_processed', 'error'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'items_processed' => 'integer',
        ];
    }

    public function company() { return $this->belongsTo(Company::class); }
}
