<?php

use App\Enums\TeamRole;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;

/**
 * Onboarding checklist (Epic 09, FR-DASH-2, AC-2): a brand-new tenant sees
 * the guided checklist, steps tick off as staff, service, and availability
 * are added, and once all three exist the metrics replace the checklist.
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);
});

/**
 * The rendered dashboard HTML for the test team.
 */
function onboardingDashboard(User $owner, Team $team): string
{
    return test()->actingAs($owner)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->getContent();
}

test('a brand-new tenant sees the checklist with every step open and the first step current', function () {
    $html = onboardingDashboard($this->owner, $this->team);

    expect($html)->toContain('data-test="onboarding-checklist"')
        ->toContain('data-test="onboarding-step-staff"')
        ->toContain('Add a staff member')
        ->toContain('Add a service')
        ->toContain('Set availability')
        ->toContain('Share your booking link')
        ->not->toContain('data-test="dashboard-metrics"');

    expect($html)->toMatch('/data-test="onboarding-step-staff"\s+data-state="current"/')
        ->toMatch('/data-test="onboarding-step-service"\s+data-state="todo"/')
        ->toMatch('/data-test="onboarding-step-availability"\s+data-state="todo"/');
});

test('the staff step ticks off and the service step becomes current once a staff member exists', function () {
    Staff::factory()->create(['team_id' => $this->team->id]);

    $html = onboardingDashboard($this->owner, $this->team);

    expect($html)->toMatch('/data-test="onboarding-step-staff"\s+data-state="done"/')
        ->toMatch('/data-test="onboarding-step-service"\s+data-state="current"/');
});

test('the availability step becomes current once staff and service exist', function () {
    $staff = Staff::factory()->create(['team_id' => $this->team->id]);
    Service::factory()->create(['team_id' => $this->team->id]);

    $html = onboardingDashboard($this->owner, $this->team);

    expect($html)->toMatch('/data-test="onboarding-step-staff"\s+data-state="done"/')
        ->toMatch('/data-test="onboarding-step-service"\s+data-state="done"/')
        ->toMatch('/data-test="onboarding-step-availability"\s+data-state="current"/')
        ->toContain(route('staff.availability', ['current_team' => $this->team->slug, 'staff' => $staff->id]));
});

test('the booking-link step shows the public booking URL with a copy button', function () {
    $html = onboardingDashboard($this->owner, $this->team);

    expect($html)->toContain('data-test="onboarding-step-share"')
        ->toContain('data-test="copy-booking-link"')
        ->toContain('value="'.route('booking.show', ['tenant' => $this->team->slug]).'"');
});

test('once staff, service, and availability exist the metrics replace the checklist', function () {
    $staff = Staff::factory()->create(['team_id' => $this->team->id]);
    Service::factory()->create(['team_id' => $this->team->id]);
    AvailabilityRule::factory()->window(1, '09:00', '17:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $staff->id,
    ]);

    $html = onboardingDashboard($this->owner, $this->team);

    expect($html)->toContain('data-test="dashboard-metrics"')
        ->toContain('data-test="metric-today"')
        ->toContain('data-test="metric-upcoming"')
        ->toContain('data-test="staff-load"')
        ->toContain('data-test="recent-bookings"')
        ->not->toContain('data-test="onboarding-checklist"');
});

test('the quick links point at appointments, staff, services, and the public booking page', function () {
    $html = onboardingDashboard($this->owner, $this->team);

    expect($html)->toContain('data-test="quick-links"')
        ->toContain(route('appointments.index', ['current_team' => $this->team->slug]))
        ->toContain(route('staff.index', ['current_team' => $this->team->slug]))
        ->toContain(route('services.index', ['current_team' => $this->team->slug]))
        ->toContain('data-test="booking-page-link"');
});
