<?php

use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;

/**
 * Accessibility and JS-error smoke tests for the dashboard (Epic 09,
 * AC-4, QG-A11Y): the dashboard is an authenticated page the pa11y tool
 * gate cannot reach, so axe runs inside the browser tests, in both the
 * onboarding and the metrics state.
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['timezone' => 'UTC']);
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);

    $this->actingAs($this->owner);
});

test('the onboarding state is accessible and error free, with a focusable copy-link button', function () {
    $page = visit(route('dashboard', ['current_team' => $this->team->slug], absolute: false))
        ->assertSee('Set up your booking page')
        ->assertSee('Share your booking link')
        ->assertVisible('@copy-booking-link');

    $page->script('document.querySelector(\'[data-test="copy-booking-link"]\').focus()');

    expect($page->script('document.activeElement.dataset.test'))->toBe('copy-booking-link');

    $page->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the metrics state is accessible and error free', function () {
    $staffMembers = Staff::factory()->count(2)->create(['team_id' => $this->team->id]);
    $service = Service::factory()->create(['team_id' => $this->team->id, 'duration_minutes' => 60]);
    $service->staff()->sync($staffMembers->pluck('id'));

    foreach ($staffMembers as $staffMember) {
        AvailabilityRule::factory()->window(now('UTC')->isoWeekday(), '08:00', '18:00')->create([
            'team_id' => $this->team->id,
            'staff_id' => $staffMember->id,
        ]);
    }

    foreach ([9, 11, 14] as $index => $hour) {
        Appointment::factory()
            ->for($this->team, 'team')
            ->for($staffMembers[$index % 2], 'staff')
            ->for($service, 'service')
            ->for(Customer::factory()->state(['team_id' => $this->team->id]), 'customer')
            ->between(
                now('UTC')->setTime($hour, 0)->toIso8601String(),
                now('UTC')->setTime($hour + 1, 0)->toIso8601String(),
            )
            ->create();
    }

    visit(route('dashboard', ['current_team' => $this->team->slug], absolute: false))
        ->assertSee('Upcoming (next 7 days)')
        ->assertSee('Recent bookings')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});
