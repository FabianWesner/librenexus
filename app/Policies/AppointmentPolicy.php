<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\Team;
use App\Models\User;

/**
 * Authorization for appointments (SEC-AUTHZ, FR-APPT-2): every member of
 * the team may open the appointment views (the listing queries are
 * additionally restricted server-side), owners and admins act on all
 * appointments, and a staff-role member acts only on the appointments of
 * the staff record linked to their own membership. Appointments are also
 * tenant-scoped by the BelongsToTenant trait (SEC-TENANT).
 */
class AppointmentPolicy
{
    /**
     * Determine whether the user can view the appointment list and the
     * calendar of the team (FR-APPT-1). What the list contains is further
     * restricted per role by the page queries (FR-APPT-2).
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can view the appointment: admins and
     * owners see all, a staff-role member only their own, where "own"
     * means the appointment's staff record is linked to the user's
     * membership in that team (FR-APPT-2).
     */
    public function view(User $user, Appointment $appointment): bool
    {
        return $this->managesOrOwns($user, $appointment);
    }

    /**
     * Determine whether the user can create appointments on the team
     * (FR-APPT-3): admins/owners always, a staff-role member only when a
     * staff record is linked to their membership. The restriction that a
     * staff-role member may only book their own record is enforced where
     * the target staff member is known (component validation), since
     * create() has no target model.
     */
    public function create(User $user, Team $team): bool
    {
        if ($this->manages($user, $team)) {
            return true;
        }

        return $user->staffRecordFor($team) !== null;
    }

    /**
     * Determine whether the user can update the appointment, which covers
     * status transitions, rescheduling, and cancelling (FR-APPT-3/4):
     * admins/owners for all, staff-role members for their own (FR-APPT-2).
     */
    public function update(User $user, Appointment $appointment): bool
    {
        return $this->managesOrOwns($user, $appointment);
    }

    /**
     * Determine whether the user manages all appointments of the team
     * (owner or admin) or the appointment belongs to the staff record
     * linked to the user's own membership (FR-APPT-2).
     */
    private function managesOrOwns(User $user, Appointment $appointment): bool
    {
        if ($this->manages($user, $appointment->team)) {
            return true;
        }

        return $appointment->staff->membership?->user_id === $user->id;
    }

    /**
     * Determine whether the user manages appointments on the team (owner
     * or admin, FR-TENANT-5).
     */
    private function manages(User $user, Team $team): bool
    {
        return $user->teamRole($team)?->isAtLeast(TeamRole::Admin) ?? false;
    }
}
