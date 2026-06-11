<?php

use App\Concerns\BelongsToTenant;
use App\Models\Membership;
use App\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Tenancy fabric allowlist (SEC-TENANT, ADR-0002): Membership and
 * TeamInvitation carry a team_id but ARE the membership fabric itself.
 * They are pre-membership/membership objects that must be queryable
 * before a tenant context exists (accepting an invitation, resolving a
 * user's teams) and every access path is guarded by the TeamPolicy.
 */
const TENANT_SCOPE_ALLOWLIST = [
    Membership::class,
    TeamInvitation::class,
];

test('every model with a team_id column uses the BelongsToTenant trait', function () {
    $modelClasses = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path) => 'App\\Models\\'.basename($path, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Model::class))
        ->reject(fn (string $class) => new ReflectionClass($class)->isAbstract());

    expect($modelClasses->count())->toBeGreaterThan(0);

    foreach ($modelClasses as $class) {
        /** @var Model $model */
        $model = new $class;

        if (! Schema::hasColumn($model->getTable(), 'team_id')) {
            continue;
        }

        if (in_array($class, TENANT_SCOPE_ALLOWLIST, true)) {
            continue;
        }

        expect(in_array(BelongsToTenant::class, class_uses_recursive($class), true))
            ->toBeTrue("Model [{$class}] has a team_id column but does not use the BelongsToTenant trait.");
    }
});
