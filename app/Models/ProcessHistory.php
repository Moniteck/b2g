<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessHistory extends Model
{
    protected $fillable = ['institution_id', 'process_number', 'name', 'modality', 'category', 'participated', 'decision', 'estimated_amount', 'offered_amount', 'result', 'result_metadata', 'cause', 'published_at', 'closed_at', 'notes'];
    protected function casts(): array { return ['estimated_amount' => 'decimal:2', 'offered_amount' => 'decimal:2', 'participated' => 'boolean', 'result_metadata' => 'array', 'published_at' => 'date', 'closed_at' => 'date']; }
    public function institution() { return $this->belongsTo(Institution::class); }
}
