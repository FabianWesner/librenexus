<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Role-permission matrix (FR-TENANT-5, AC-2): the TeamPolicy is the server
 * side source of truth for what each role may do on a non-personal team.
 */
test('the team policy enforces the role permission matrix', function (TeamRole $role, array $abilities) {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    foreach ($abilities as $ability => $allowed) {
        expect(Gate::forUser($user)->allows($ability, $team))
            ->toBe($allowed, "Expected [{$role->value}] ability [{$ability}] to be ".($allowed ? 'allowed' : 'denied'));
    }
})->with([
    'owner' => [TeamRole::Owner, [
        'view' => true,
        'update' => true,
        'inviteMember' => true,
        'cancelInvitation' => true,
        'addMember' => true,
        'updateMember' => true,
        'removeMember' => true,
        'transferOwnership' => true,
        'delete' => true,
    ]],
    'admin' => [TeamRole::Admin, [
        'view' => true,
        'update' => true,
        'inviteMember' => true,
        'cancelInvitation' => true,
        'addMember' => false,
        'updateMember' => false,
        'removeMember' => false,
        'transferOwnership' => false,
        'delete' => false,
    ]],
    'staff' => [TeamRole::Staff, [
        'view' => true,
        'update' => false,
        'inviteMember' => false,
        'cancelInvitation' => false,
        'addMember' => false,
        'updateMember' => false,
        'removeMember' => false,
        'transferOwnership' => false,
        'delete' => false,
    ]],
]);

test('the team policy denies every team ability for non-members', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    foreach (['view', 'update', 'inviteMember', 'cancelInvitation', 'updateMember', 'removeMember', 'transferOwnership', 'delete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $team))->toBeFalse("Expected non-member ability [{$ability}] to be denied");
    }
});

test('any user may list and create teams', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', Team::class))->toBeTrue();
    expect(Gate::forUser($user)->allows('create', Team::class))->toBeTrue();
});

test('an owner can update the team through the settings screen', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamName', 'Owner Renamed')
        ->call('updateTeam')
        ->assertHasNoErrors();

    expect($team->fresh()->name)->toBe('Owner Renamed');
});

test('an admin can update the team but not member roles', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $staff = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($staff, ['role' => TeamRole::Staff->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamName', 'Admin Renamed')
        ->call('updateTeam')
        ->assertHasNoErrors();

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->call('updateMember', $staff->id, TeamRole::Admin->value)
        ->assertForbidden();

    expect($team->fresh()->name)->toBe('Admin Renamed');
    expect($staff->fresh()->teamRole($team))->toBe(TeamRole::Staff);
});

test('a staff member can view the team but not update it', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($staff, ['role' => TeamRole::Staff->value]);

    $this->actingAs($staff)
        ->get(route('teams.edit', $team))
        ->assertOk();

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamName', 'Staff Renamed')
        ->call('updateTeam')
        ->assertForbidden();

    expect($team->fresh()->name)->not->toBe('Staff Renamed');
});
