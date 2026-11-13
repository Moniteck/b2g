<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class KpiGoal extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'metric', 'target', 'period_start', 'period_end', 'updated_by'];

    protected function casts(): array
    {
        return ['target' => 'decimal:2', 'period_start' => 'date', 'period_end' => 'date'];
    }
}
