<?php

namespace App\Concerns;

use App\Data\CurrentTenant;
use App\Models\Scopes\TenantScope;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Opt-in for tenant-owned models (SEC-TENANT, ADR-0002). Applies the
 * fail-closed TenantScope and fills team_id from the active tenant on
 * create. An arch test asserts every model with a team_id column outside
 * the tenancy fabric uses this trait.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenantId = app(CurrentTenant::class)->id();
            $assignedTeamId = $model->getAttribute('team_id');

            if ($tenantId !== null) {
                // With a tenant context (every tenant-scoped request), a
                // differing pre-set team_id is a spoof attempt (SEC-TENANT-2);
                // it must match or be absent.
                if ($assignedTeamId !== null && (int) $assignedTeamId !== $tenantId) {
                    throw new RuntimeException(sprintf(
                        'Refusing to create [%s] for a tenant other than the active one.',
                        $model::class,
                    ));
                }

                $model->setAttribute('team_id', $tenantId);

                return;
            }

            // Without a context, only trusted code with an explicit team_id
            // (factories, seeders) may create; requests always have a context.
            if ($assignedTeamId === null) {
                throw new RuntimeException(sprintf(
                    'Cannot create [%s] without an active tenant context.',
                    $model::class,
                ));
            }
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
