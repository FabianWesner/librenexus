<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * Tenant settings (FR-TENANT-8, FR-SETTINGS-3, AC-6): profile and booking
 * policy are editable by owner/admin only; defaults exist after creation.
 */
function settingsTeamWithRole(TeamRole $role): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return [$user, $team];
}

test('booking policy defaults are present after team creation', function () {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Fresh Practice');

    $team->refresh();

    expect($team->timezone)->toBe('UTC')
        ->and($team->locale)->toBe('en')
        ->and($team->currency)->toBe('EUR')
        ->and($team->contact_email)->toBeNull()
        ->and($team->minimum_lead_time_minutes)->toBe(120)
        ->and($team->booking_horizon_days)->toBe(60)
        ->and($team->cancellation_cutoff_minutes)->toBe(120)
        ->and($team->reminder_lead_time_hours)->toBe(24)
        ->and($team->requires_approval)->toBeFalse();
});

test('team creation accepts optional profile attributes', function () {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Zoned Practice', attributes: [
        'timezone' => 'Europe/Berlin',
        'contact_email' => 'office@example.com',
    ]);

    $team->refresh();

    expect($team->timezone)->toBe('Europe/Berlin')
        ->and($team->contact_email)->toBe('office@example.com');
});

test('the profile can be updated by role', function (TeamRole $role) {
    [$user, $team] = settingsTeamWithRole($role);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamName', 'Updated Practice')
        ->set('teamTimezone', 'Europe/Berlin')
        ->set('teamContactEmail', 'contact@example.com')
        ->set('teamCurrency', 'CHF')
        ->call('updateTeam')
        ->assertHasNoErrors();

    $team->refresh();

    expect($team->name)->toBe('Updated Practice')
        ->and($team->timezone)->toBe('Europe/Berlin')
        ->and($team->contact_email)->toBe('contact@example.com')
        ->and($team->currency)->toBe('CHF');
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
]);

test('the booking policy can be updated by role', function (TeamRole $role) {
    [$user, $team] = settingsTeamWithRole($role);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('minimumLeadTimeMinutes', 60)
        ->set('bookingHorizonDays', 90)
        ->set('cancellationCutoffMinutes', 240)
        ->set('reminderLeadTimeHours', 48)
        ->set('requiresApproval', true)
        ->call('updateBookingPolicy')
        ->assertHasNoErrors();

    $team->refresh();

    expect($team->minimum_lead_time_minutes)->toBe(60)
        ->and($team->booking_horizon_days)->toBe(90)
        ->and($team->cancellation_cutoff_minutes)->toBe(240)
        ->and($team->reminder_lead_time_hours)->toBe(48)
        ->and($team->requires_approval)->toBeTrue();
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
]);

test('staff cannot update the profile or booking policy', function () {
    [$user, $team] = settingsTeamWithRole(TeamRole::Staff);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamName', 'Staff Renamed')
        ->call('updateTeam')
        ->assertForbidden();

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('minimumLeadTimeMinutes', 0)
        ->call('updateBookingPolicy')
        ->assertForbidden();
});

test('the slug can be changed and the old URL stops working', function () {
    [$user, $team] = settingsTeamWithRole(TeamRole::Owner);
    $oldSlug = $team->slug;

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamSlug', 'renamed-practice')
        ->call('updateTeam')
        ->assertHasNoErrors();

    expect($team->fresh()->slug)->toBe('renamed-practice');

    $this->get(route('dashboard', ['current_team' => $oldSlug]))->assertNotFound();
    $this->get(route('dashboard', ['current_team' => 'renamed-practice']))->assertOk();
});

test('reserved slugs are rejected', function (string $slug) {
    [$user, $team] = settingsTeamWithRole(TeamRole::Owner);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamSlug', $slug)
        ->call('updateTeam')
        ->assertHasErrors(['teamSlug']);

    expect($team->fresh()->slug)->not->toBe($slug);
})->with(['pricing', 'login', 'book']);

test('duplicate slugs are rejected', function () {
    [$user, $team] = settingsTeamWithRole(TeamRole::Owner);
    $otherTeam = Team::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamSlug', $otherTeam->slug)
        ->call('updateTeam')
        ->assertHasErrors(['teamSlug']);
});

test('invalid slug formats are rejected', function (string $slug) {
    [$user, $team] = settingsTeamWithRole(TeamRole::Owner);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamSlug', $slug)
        ->call('updateTeam')
        ->assertHasErrors(['teamSlug']);
})->with([
    'uppercase' => 'My-Practice',
    'spaces' => 'my practice',
    'leading hyphen' => '-practice',
    'trailing hyphen' => 'practice-',
    'double hyphen' => 'my--practice',
    'special characters' => 'my_practice!',
    'too long' => str_repeat('a', 65),
]);

test('booking policy bounds are validated', function (string $field, int $value) {
    [$user, $team] = settingsTeamWithRole(TeamRole::Owner);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set($field, $value)
        ->call('updateBookingPolicy')
        ->assertHasErrors([$field]);
})->with([
    'lead time below minimum' => ['minimumLeadTimeMinutes', -1],
    'lead time above maximum' => ['minimumLeadTimeMinutes', 10081],
    'horizon below minimum' => ['bookingHorizonDays', 0],
    'horizon above maximum' => ['bookingHorizonDays', 366],
    'cutoff above maximum' => ['cancellationCutoffMinutes', 10081],
    'reminder below minimum' => ['reminderLeadTimeHours', 0],
    'reminder above maximum' => ['reminderLeadTimeHours', 169],
]);
