<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;

test('the tenant settings page is accessible and error free', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    visit(route('teams.edit', $team, absolute: false))
        ->assertSee('Booking policy')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the teams index page is accessible and error free', function () {
    $this->actingAs(User::factory()->create());

    visit(route('teams.index', absolute: false))
        ->assertSee('Teams')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('accepting an invitation lands on the team dashboard without errors', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitee = User::factory()->create(['email' => 'invitee@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invitee@example.com',
        'role' => TeamRole::Staff,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitee);

    visit(route('invitations.accept', $invitation, absolute: false))
        ->assertPathIs('/'.$team->slug.'/dashboard')
        ->assertNoJavascriptErrors();

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect($invitee->fresh()->belongsToTeam($team))->toBeTrue();

    // The accept route redirects immediately, so run the accessibility
    // audit on the teams index the invitee sees afterwards instead.
    visit(route('teams.index', absolute: false))
        ->assertNoAccessibilityIssues();
});
