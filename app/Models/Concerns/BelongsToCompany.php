<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marca el modelo como perteneciente a una empresa del grupo.
 *
 * - Las consultas se limitan a la empresa activa (ver CompanyScope).
 * - Al crear, si `company_id` viene vacío se asigna la empresa activa.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if (empty($model->company_id)) {
                $model->company_id = app(CompanyContext::class)->currentCompanyId();
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany($query, ?int $companyId)
    {
        return $query->where($query->getModel()->qualifyColumn('company_id'), $companyId);
    }
}
