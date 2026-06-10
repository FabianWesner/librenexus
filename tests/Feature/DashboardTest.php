<?php

use App\Enums\TeamRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('a staff-role member without a linked staff record sees the link notice (AC-7)', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $staffUser = User::factory()->create();
    $team->members()->attach($staffUser, ['role' => TeamRole::Staff->value]);

    $this->actingAs($staffUser)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('not linked to a staff profile');
});

test('a staff-role member with a linked staff record does not see the link notice', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $staffUser = User::factory()->create();
    $team->members()->attach($staffUser, ['role' => TeamRole::Staff->value]);

    $membership = $team->memberships()->where('user_id', $staffUser->id)->firstOrFail();
    Staff::factory()->linkedTo($membership)->create();

    $this->actingAs($staffUser)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertDontSee('not linked to a staff profile');
});

test('owners and admins never see the staff link notice', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertDontSee('not linked to a staff profile');
});
