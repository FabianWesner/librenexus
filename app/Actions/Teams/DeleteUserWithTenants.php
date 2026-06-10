<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteUserWithTenants
{
    /**
     * Delete the user's account together with their personal tenant
     * (FR-TENANT-10). Deletion is blocked while the user is the sole owner
     * of any non-personal team; memberships elsewhere are removed without
     * touching those teams' data.
     */
    public function handle(User $user): void
    {
        $this->ensureUserIsNotSoleOwner($user);

        DB::transaction(function () use ($user) {
            $personalTeam = $user->personalTeam();

            $user->teamMemberships()->delete();

            if ($personalTeam !== null) {
                $personalTeam->invitations()->delete();
                $personalTeam->delete();
            }

            $user->delete();
        });
    }

    /**
     * Block deletion while the user is the sole owner of a non-personal team
     * until they transfer ownership or delete that team (FR-TENANT-10).
     */
    public function ensureUserIsNotSoleOwner(User $user): void
    {
        $blockingTeam = $user->ownedTeams()
            ->where('is_personal', false)
            ->get()
            ->first(fn (Team $team) => $team->isLastOwner($user));

        if ($blockingTeam !== null) {
            throw ValidationException::withMessages([
                'account' => [__('You are the only owner of the team ":name". Transfer ownership or delete that team before deleting your account.', [
                    'name' => $blockingTeam->name,
                ])],
            ]);
        }
    }
}
