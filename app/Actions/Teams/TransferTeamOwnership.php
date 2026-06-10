<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferTeamOwnership
{
    /**
     * Transfer team ownership to another member (FR-TENANT-9). The new owner
     * must already be a member; every previous owner is demoted to admin so
     * the team keeps exactly one owner after the transfer.
     */
    public function handle(Team $team, User $newOwner): void
    {
        DB::transaction(function () use ($team, $newOwner) {
            $membership = $team->memberships()
                ->where('user_id', $newOwner->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw ValidationException::withMessages([
                    'member' => [__('Ownership can only be transferred to an existing team member.')],
                ]);
            }

            $team->memberships()
                ->where('role', TeamRole::Owner)
                ->where('user_id', '!=', $newOwner->id)
                ->update(['role' => TeamRole::Admin]);

            $membership->update(['role' => TeamRole::Owner]);
        });
    }
}
