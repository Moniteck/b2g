<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Consortium extends Model
{
    use BelongsToCompany;

    protected $table = 'consortia';

    protected $fillable = ['company_id', 'name', 'tender_id', 'institution_id', 'leader_company_id', 'amount', 'formed_at', 'status', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'formed_at' => 'date'];
    }

    public function members()
    {
        return $this->hasMany(ConsortiumMember::class);
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function leaderCompany()
    {
        return $this->belongsTo(Company::class, 'leader_company_id');
    }
}
