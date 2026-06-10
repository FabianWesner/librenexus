<?php

namespace App\Data;

use App\Models\Team;

/**
 * Request-scoped holder for the active tenant (ADR-0002).
 *
 * Set in exactly two places: EnsureTeamMembership for authenticated tenant
 * routes, and the public booking/manage entry points which resolve the tenant
 * from the slug or token. Everything else reads it through the container.
 */
class CurrentTenant
{
    private ?Team $tenant = null;

    public function set(Team $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Team
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
