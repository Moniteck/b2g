<?php

namespace App\Models\Concerns;

use App\Models\Scopes\CompanyScope;

/**
 * Marca el modelo como perteneciente a una empresa del grupo.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function company()
    {
        return $this->belongsTo(\App\Models\Company::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForCompany($query, ?int $companyId)
    {
        return $query->where($query->getModel()->qualifyColumn('company_id'), $companyId);
    }
}
