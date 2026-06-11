<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('team invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', TeamRole::Staff->value)
        ->call('createInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Staff->value,
    ]);
});

test('new team invitations expire after seven days', function () {
    Notification::fake();

    $this->freezeTime();

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', TeamRole::Staff->value)
        ->call('createInvitation')
        ->assertHasNoErrors();

    $invitation = TeamInvitation::query()->where('email', 'invited@example.com')->firstOrFail();

    expect($invitation->expires_at)->not->toBeNull();
    expect($invitation->expires_at->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
});

test('team invitations cannot be created by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Staff->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', TeamRole::Staff->value)
        ->call('createInvitation')
        ->assertForbidden();
});

test('team invitations can be cancelled by owner', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.cancel-invitation-modal', ['team' => $team])
        ->set('invitationCode', $invitation->code)
        ->call('cancelInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations can be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Staff,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    $response = Livewire::test('pages::teams.accept-invitation', [
        'invitation' => $invitation,
    ]);

    $response->assertRedirect(route('dashboard'));

    expect(session('team-invitation-accepted'))->toBeTrue();

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect($invitedUser->fresh()->belongsToTeam($team))->toBeTrue();
});

test('accepted invitation toast is shown on the dashboard', function () {
    $user = User::factory()->create();

    session()->flash('team-invitation-accepted', true);

    $this->actingAs($user);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->assertDispatched('toast-show');
});

test('pending invitations excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Expired Team']);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->assertDontSee('Expired Team');

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('a pending invitation can be declined without joining the team', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->call('declineInvitation', $invitation->code)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('an invitation addressed to someone else cannot be declined', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($otherUser);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->call('declineInvitation', $invitation->code)
        ->assertHasErrors(['invitation']);

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations cannot be accepted by user that wasnt invited', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($uninvitedUser);

    $response = Livewire::test('pages::teams.accept-invitation', [
        'invitation' => $invitation,
    ]);

    $response->assertHasErrors(['invitation']);

    expect($uninvitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    $response = Livewire::test('pages::teams.accept-invitation', [
        'invitation' => $invitation,
    ]);

    $response->assertHasErrors(['invitation']);

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('an invitation can never carry the owner role', function () {
    $admin = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'escalation@example.com')
        ->set('inviteRole', TeamRole::Owner->value)
        ->call('createInvitation')
        ->assertHasErrors(['inviteRole']);

    $this->assertDatabaseMissing('team_invitations', [
        'team_id' => $team->id,
        'email' => 'escalation@example.com',
    ]);
});

test('an accepted invitation cannot be accepted a second time', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitee = User::factory()->create(['email' => 'twice@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'twice@example.com',
        'invited_by' => $owner->id,
        'accepted_at' => now(),
    ]);

    $this->actingAs($invitee);

    Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation])
        ->assertHasErrors('invitation');

    expect($invitee->fresh()->currentTeam?->id)->not->toBe($team->id);
});

test('the register page carries the invitation context for an unregistered invitee', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Invitation Target Office']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newcomer@example.com',
        'invited_by' => $owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('register', ['invitation' => $invitation->code]))
        ->assertOk()
        ->assertSee('Invitation Target Office');

    $this->get(route('register', ['invitation' => 'not-a-real-code']))
        ->assertOk()
        ->assertDontSee('Invitation Target Office');
});

test('an unverified user is routed to verification before accepting an invitation', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitee = User::factory()->unverified()->create(['email' => 'unverified@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'unverified@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitee)
        ->get(route('invitations.accept', $invitation))
        ->assertRedirect(route('verification.notice'));

    expect($invitee->fresh()->belongsToTeam($team))->toBeFalse();
});
