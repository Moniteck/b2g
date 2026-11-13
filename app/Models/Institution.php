<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    protected $fillable = ['name', 'acronym', 'type', 'fleet_type', 'modality', 'contact_name', 'phone', 'email', 'potential', 'location', 'status', 'notes', 'management_notes', 'source'];

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function opportunities()
    {
        return $this->hasMany(Opportunity::class);
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class);
    }
}
