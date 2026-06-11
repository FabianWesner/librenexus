<?php

use App\Enums\TeamRole;
use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
});

test('non-members receive a 404 instead of a 403', function () {
    $this->actingAs($this->user)
        ->get(route('teams.edit', $this->team))
        ->assertNotFound();
});

test('guests receive a 404 on team routes without a session', function () {
    Route::get('membership-probe/{team}', fn () => 'ok')
        ->middleware(['web', EnsureTeamMembership::class]);

    $this->get('membership-probe/'.$this->team->slug)->assertNotFound();
});

test('members below the required role receive a 403', function () {
    Route::get('membership-probe/{team}', fn () => 'ok')
        ->middleware(['web', 'auth', EnsureTeamMembership::class.':admin']);

    $this->team->members()->attach($this->user, ['role' => TeamRole::Staff->value]);

    $this->actingAs($this->user)
        ->get('membership-probe/'.$this->team->slug)
        ->assertForbidden();
});

test('members at or above the required role pass', function () {
    Route::get('membership-probe/{team}', fn () => 'ok')
        ->middleware(['web', 'auth', EnsureTeamMembership::class.':admin']);

    $this->team->members()->attach($this->user, ['role' => TeamRole::Admin->value]);

    $this->actingAs($this->user)
        ->get('membership-probe/'.$this->team->slug)
        ->assertOk();
});

test('an unknown minimum role parameter fails closed with a 403', function () {
    Route::get('membership-probe/{team}', fn () => 'ok')
        ->middleware(['web', 'auth', EnsureTeamMembership::class.':superuser']);

    $this->team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($this->user)
        ->get('membership-probe/'.$this->team->slug)
        ->assertForbidden();
});

test('visiting a team dashboard switches the current team', function () {
    $this->team->members()->attach($this->user, ['role' => TeamRole::Staff->value]);

    expect($this->user->current_team_id)->not->toBe($this->team->id);

    $this->actingAs($this->user)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertOk();

    expect($this->user->fresh()->current_team_id)->toBe($this->team->id);
});

test('memberships expose their team and user relations', function () {
    $this->team->members()->attach($this->user, ['role' => TeamRole::Staff->value]);

    $membership = Membership::query()
        ->where('team_id', $this->team->id)
        ->where('user_id', $this->user->id)
        ->firstOrFail();

    expect($membership->team->is($this->team))->toBeTrue()
        ->and($membership->user->is($this->user))->toBeTrue()
        ->and($membership->role)->toBe(TeamRole::Staff);
});
