<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRole;

test('roles expose human readable labels', function () {
    expect(TeamRole::Owner->label())->toBe('Owner')
        ->and(TeamRole::Admin->label())->toBe('Admin')
        ->and(TeamRole::Staff->label())->toBe('Staff');
});

test('owners hold every permission', function () {
    expect(TeamRole::Owner->permissions())->toBe(TeamPermission::cases());
});

test('admins hold exactly the team update and invitation permissions', function () {
    expect(TeamRole::Admin->permissions())->toBe([
        TeamPermission::UpdateTeam,
        TeamPermission::CreateInvitation,
        TeamPermission::CancelInvitation,
    ]);
});

test('staff hold no team management permissions', function () {
    expect(TeamRole::Staff->permissions())->toBe([]);
});

test('hasPermission reflects the role permission list', function () {
    expect(TeamRole::Owner->hasPermission(TeamPermission::TransferOwnership))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::UpdateTeam))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::RemoveMember))->toBeFalse()
        ->and(TeamRole::Staff->hasPermission(TeamPermission::UpdateTeam))->toBeFalse();
});

test('isAtLeast follows the owner, admin, staff hierarchy', function () {
    expect(TeamRole::Owner->isAtLeast(TeamRole::Admin))->toBeTrue()
        ->and(TeamRole::Owner->isAtLeast(TeamRole::Owner))->toBeTrue()
        ->and(TeamRole::Admin->isAtLeast(TeamRole::Owner))->toBeFalse()
        ->and(TeamRole::Admin->isAtLeast(TeamRole::Staff))->toBeTrue()
        ->and(TeamRole::Staff->isAtLeast(TeamRole::Admin))->toBeFalse()
        ->and(TeamRole::Staff->isAtLeast(TeamRole::Staff))->toBeTrue();
});

test('assignable roles exclude the owner role', function () {
    expect(TeamRole::assignable())->toBe([
        ['value' => 'admin', 'label' => 'Admin'],
        ['value' => 'staff', 'label' => 'Staff'],
    ]);
});
