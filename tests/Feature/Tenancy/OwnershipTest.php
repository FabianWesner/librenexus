<?php

use App\Actions\Teams\TransferTeamOwnership;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Ownership lifecycle (FR-TENANT-9/10, AC-7): a team always keeps at least
 * one owner; ownership can be transferred; sole owners cannot delete their
 * account without transferring or deleting the team first.
 */
test('ownership can be transferred to another member', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Staff->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.transfer-ownership-modal', ['team' => $team, 'memberId' => $member->id])
        ->call('transferOwnership')
        ->assertHasNoErrors();

    expect($member->fresh()->teamRole($team))->toBe(TeamRole::Owner);
    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Admin);
});

test('ownership cannot be transferred to a non-member', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.transfer-ownership-modal', ['team' => $team, 'memberId' => $outsider->id])
        ->call('transferOwnership')
        ->assertHasErrors(['member']);

    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Owner);
    expect($outsider->fresh()->belongsToTeam($team))->toBeFalse();
});

test('ownership cannot be transferred by an admin', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.transfer-ownership-modal', ['team' => $team, 'memberId' => $admin->id])
        ->call('transferOwnership')
        ->assertForbidden();

    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});

test('a personal team cannot be transferred', function () {
    $user = User::factory()->create();
    $member = User::factory()->create();
    $personalTeam = $user->personalTeam();
    $personalTeam->members()->attach($member, ['role' => TeamRole::Staff->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.transfer-ownership-modal', ['team' => $personalTeam, 'memberId' => $member->id])
        ->call('transferOwnership')
        ->assertForbidden();

    expect($user->fresh()->teamRole($personalTeam))->toBe(TeamRole::Owner);
});

test('the transfer action rejects non-members with a validation error', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    expect(fn () => app(TransferTeamOwnership::class)->handle($team, $outsider))
        ->toThrow(ValidationException::class);
});

test('the last owner cannot be demoted', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Staff->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->call('updateMember', $owner->id, TeamRole::Admin->value)
        ->assertHasErrors(['role']);

    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});

test('an owner can be demoted while another owner remains', function () {
    $owner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($secondOwner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->call('updateMember', $secondOwner->id, TeamRole::Admin->value)
        ->assertHasNoErrors();

    expect($secondOwner->fresh()->teamRole($team))->toBe(TeamRole::Admin);
});

test('the last owner cannot be removed from the team', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Staff->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.remove-member-modal', ['team' => $team])
        ->set('memberId', $owner->id)
        ->call('removeMember')
        ->assertHasErrors(['member']);

    expect($owner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('an owner can remove themselves when another owner exists', function () {
    $owner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($secondOwner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.remove-member-modal', ['team' => $team])
        ->set('memberId', $owner->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    expect($owner->fresh()->belongsToTeam($team))->toBeFalse();
    expect($secondOwner->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});

test('account deletion is blocked while the user is the sole owner of a non-personal team', function () {
    $user = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Staff->value]);

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['account']);

    expect($user->fresh())->not->toBeNull();
    expect(auth()->check())->toBeTrue();
});

test('account deletion removes the personal team and unlinks other memberships', function () {
    $user = User::factory()->create();
    $otherOwner = User::factory()->create();
    $personalTeam = $user->personalTeam();

    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($otherOwner, ['role' => TeamRole::Owner->value]);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Staff->value]);

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();

    $this->assertSoftDeleted('teams', ['id' => $personalTeam->id]);
    $this->assertDatabaseMissing('team_members', ['user_id' => $user->id]);

    expect($otherTeam->fresh()->deleted_at)->toBeNull();
    expect($otherOwner->fresh()->teamRole($otherTeam))->toBe(TeamRole::Owner);
});

test('account deletion succeeds after transferring ownership', function () {
    $user = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Staff->value]);

    app(TransferTeamOwnership::class)->handle($team, $member);

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors();

    expect($user->fresh())->toBeNull();
    expect($member->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});
