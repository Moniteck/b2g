<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InstitutionalRelation extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'institution_id', 'contact_id', 'owner_id', 'relationship_type', 'influence', 'channel', 'status', 'started_at', 'last_contact_at', 'next_contact_at', 'poa_interest', 'notes'];

    protected function casts(): array
    {
        return ['started_at' => 'date', 'last_contact_at' => 'date', 'next_contact_at' => 'date'];
    }

    public function interactions()
    {
        return $this->hasMany(RelationInteraction::class);
    }
}
