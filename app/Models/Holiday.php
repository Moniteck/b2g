<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Feriado de una empresa: día no hábil para los plazos en días hábiles.
 */
class Holiday extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'holiday_date', 'name', 'is_pending_review', 'created_by', 'updated_by', 'deleted_by'];

    /** Se guarda solo la fecha (sin hora) para que la unicidad por día funcione en cualquier motor. */
    public function setHolidayDateAttribute(mixed $value): void
    {
        $this->attributes['holiday_date'] = $value === null ? null : Carbon::parse($value)->toDateString();
    }

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_pending_review' => 'boolean',
        ];
    }
}
