<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;

/**
 * Authorization for services (SEC-AUTHZ, FR-TENANT-5): every member of the
 * team may view, only owners and admins may manage. Services are
 * additionally tenant-scoped by the BelongsToTenant trait (SEC-TENANT).
 */
class ServicePolicy
{
    /**
     * Determine whether the user can view the service list of the team.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can view the service.
     */
    public function view(User $user, Service $service): bool
    {
        return $user->belongsToTeam($service->team);
    }

    /**
     * Determine whether the user can create services on the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $this->manages($user, $team);
    }

    /**
     * Determine whether the user can update the service.
     */
    public function update(User $user, Service $service): bool
    {
        return $this->manages($user, $service->team);
    }

    /**
     * Determine whether the user can delete the service.
     */
    public function delete(User $user, Service $service): bool
    {
        return $this->manages($user, $service->team);
    }

    /**
     * Determine whether the user manages services on the team (owner or admin).
     */
    private function manages(User $user, Team $team): bool
    {
        return $user->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false;
    }
}
