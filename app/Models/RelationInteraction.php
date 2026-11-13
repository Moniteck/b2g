<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RelationInteraction extends Model
{
    protected $fillable = ['institutional_relation_id', 'type', 'description', 'occurred_at', 'result', 'next_action_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'next_action_at' => 'date'];
    }
}
