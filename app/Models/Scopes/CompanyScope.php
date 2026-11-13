<?php

namespace App\Models\Scopes;

use App\Services\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limita automaticamente las consultas a las empresas permitidas al actor.
 *
 * Cuando no hay contexto (consola, colas sin empresa asignada) la consulta no
 * se restringe, porque esas operaciones reciben la empresa de forma explicita.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CompanyContext::class);

        if ($context->isUnrestricted()) {
            return;
        }

        $column = $model->qualifyColumn('company_id');
        $allowed = $context->allowedCompanyIds();

        $builder->whereIn($column, $allowed === [] ? [0] : $allowed);
    }
}
