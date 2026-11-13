<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = ['institution_id', 'name', 'position', 'department', 'email', 'phone', 'whatsapp', 'type'];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
