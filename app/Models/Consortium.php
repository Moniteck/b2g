<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Consortium extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'tender_id', 'institution_id', 'leader_company_id', 'amount', 'formed_at', 'status', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'formed_at' => 'date'];
    }

    public function members()
    {
        return $this->hasMany(ConsortiumMember::class);
    }
}
