<?php

namespace App\Models\Scopes;

use App\Data\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope constraining every query to the active tenant (SEC-TENANT,
 * ADR-0002). Fails closed: without a tenant context, queries match nothing.
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId === null) {
            // Fail closed (constant expression, no user input involved).
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('team_id'), $tenantId);
    }
}
