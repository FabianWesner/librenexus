<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateMemberRole
{
    /**
     * Update a team member's role (FR-TENANT-9). The membership row is
     * locked inside the transaction so a concurrent demotion can never
     * strip the team of its last owner.
     */
    public function handle(Team $team, int $userId, TeamRole $newRole): void
    {
        DB::transaction(function () use ($team, $userId, $newRole) {
            $membership = $team->memberships()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->role === TeamRole::Owner && $newRole !== TeamRole::Owner && $team->isLastOwner($membership->user)) {
                throw ValidationException::withMessages([
                    'role' => [__('This member is the last owner. Transfer ownership before changing their role.')],
                ]);
            }

            $membership->update(['role' => $newRole]);
        });
    }
}
