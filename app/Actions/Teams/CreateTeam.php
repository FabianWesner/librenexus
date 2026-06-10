<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * Create a new team and add the user as owner. Profile attributes are
     * optional; booking-policy defaults come from the database (FR-TENANT-8).
     *
     * @param  array{timezone?: string, contact_email?: string|null, locale?: string, currency?: string}  $attributes
     */
    public function handle(User $user, string $name, bool $isPersonal = false, array $attributes = []): Team
    {
        return DB::transaction(function () use ($user, $name, $isPersonal, $attributes) {
            $team = Team::create([
                ...$attributes,
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
            ]);

            $user->switchTeam($team);

            return $team;
        });
    }
}
