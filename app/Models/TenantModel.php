<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for tenant-owned models (SEC-TENANT, ADR-0002). Extending it
 * wires up the BelongsToTenant trait, so every query is constrained to the
 * active tenant and team_id is filled automatically on create. A scope test
 * (tests/Unit/TenantScopingTest.php) asserts that every model with a team_id
 * column outside the tenancy fabric uses the trait.
 */
abstract class TenantModel extends Model
{
    use BelongsToTenant;
}
