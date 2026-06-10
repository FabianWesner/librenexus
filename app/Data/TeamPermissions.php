<?php

namespace App\Data;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;

readonly class TeamPermissions
{
    public bool $canUpdateTeam;

    public bool $canDeleteTeam;

    public bool $canTransferOwnership;

    public bool $canAddMember;

    public bool $canUpdateMember;

    public bool $canRemoveMember;

    public bool $canCreateInvitation;

    public bool $canCancelInvitation;

    public function __construct(?TeamRole $role)
    {
        $this->canUpdateTeam = $this->allows($role, TeamPermission::UpdateTeam);
        $this->canDeleteTeam = $this->allows($role, TeamPermission::DeleteTeam);
        $this->canTransferOwnership = $this->allows($role, TeamPermission::TransferOwnership);
        $this->canAddMember = $this->allows($role, TeamPermission::AddMember);
        $this->canUpdateMember = $this->allows($role, TeamPermission::UpdateMember);
        $this->canRemoveMember = $this->allows($role, TeamPermission::RemoveMember);
        $this->canCreateInvitation = $this->allows($role, TeamPermission::CreateInvitation);
        $this->canCancelInvitation = $this->allows($role, TeamPermission::CancelInvitation);
    }

    private function allows(?TeamRole $role, TeamPermission $permission): bool
    {
        return $role?->hasPermission($permission) ?? false;
    }
}
