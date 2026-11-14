<?php

namespace App\Models\Scopes;

use App\Services\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limita automáticamente las consultas a la EMPRESA ACTIVA del actor.
 *
 * Cuando no hay contexto (consola, colas sin empresa asignada) la consulta no
 * se restringe, porque esas operaciones reciben la empresa de forma explícita.
 * Para consultar otra empresa permitida se usa `CompanyContext::runFor()` o
 * `Model::withoutGlobalScope(CompanyScope::class)` filtrando a mano.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CompanyContext::class);

        if ($context->isUnrestricted()) {
            return;
        }

        // Sin empresa activa (usuario sin membresías) no se ve nada.
        $builder->where($model->qualifyColumn('company_id'), $context->currentCompanyId() ?? 0);
    }
}
