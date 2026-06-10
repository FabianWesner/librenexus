<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;

/**
 * Authorization for staff records (SEC-AUTHZ, FR-TENANT-5): every member of
 * the team may view, only owners and admins may manage. Staff records are
 * additionally tenant-scoped by the BelongsToTenant trait (SEC-TENANT).
 */
class StaffPolicy
{
    /**
     * Determine whether the user can view the staff list of the team.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can view the staff record.
     */
    public function view(User $user, Staff $staff): bool
    {
        return $user->belongsToTeam($staff->team);
    }

    /**
     * Determine whether the user can create staff records on the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $this->manages($user, $team);
    }

    /**
     * Determine whether the user can update the staff record. Linking and
     * unlinking a membership is part of update (admin-only, FR-STAFF-4).
     */
    public function update(User $user, Staff $staff): bool
    {
        return $this->manages($user, $staff->team);
    }

    /**
     * Determine whether the user can delete the staff record.
     */
    public function delete(User $user, Staff $staff): bool
    {
        return $this->manages($user, $staff->team);
    }

    /**
     * Determine whether the user can manage the availability (weekly rules
     * and time off) of the staff record. Owners and admins manage every
     * staff member of their team (FR-TENANT-5); a staff-role member manages
     * exactly the staff record linked to their own membership (FR-STAFF-4).
     */
    public function manageAvailability(User $user, Staff $staff): bool
    {
        if ($this->manages($user, $staff->team)) {
            return true;
        }

        return $staff->membership?->user_id === $user->id;
    }

    /**
     * Determine whether the user manages staff on the team (owner or admin).
     */
    private function manages(User $user, Team $team): bool
    {
        return $user->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false;
    }
}
