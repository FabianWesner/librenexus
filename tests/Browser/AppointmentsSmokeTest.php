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
 * Accessibility and JS-error smoke tests for the appointment management
 * pages (Epic 07, QG-A11Y): authenticated pages cannot be reached by the
 * pa11y tool gate, so axe runs inside the browser tests (test-plan.md
 * §Accessibility & performance per page).
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['timezone' => 'UTC']);
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);

    $staffMembers = Staff::factory()->count(2)->create(['team_id' => $this->team->id]);
    $service = Service::factory()->create(['team_id' => $this->team->id, 'duration_minutes' => 60]);
    $service->staff()->sync($staffMembers->pluck('id'));

    foreach ($staffMembers as $staffMember) {
        AvailabilityRule::factory()->window(now('UTC')->isoWeekday(), '08:00', '18:00')->create([
            'team_id' => $this->team->id,
            'staff_id' => $staffMember->id,
        ]);
    }

    // Rows for both views: blocks on today's calendar and list entries.
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

    $this->actingAs($this->owner);
});

test('the appointments list is accessible and error free', function () {
    visit(route('appointments.index', ['current_team' => $this->team->slug], absolute: false))
        ->assertSee('Appointments')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the new appointment modal is accessible and error free', function () {
    $page = visit(route('appointments.index', ['current_team' => $this->team->slug], absolute: false))
        ->click('New appointment')
        ->assertSee('Book a slot for a customer');

    // Headless rendering produces no animation frames, so the dialog's
    // open transition stays pending at opacity 0 and axe would treat the
    // whole modal as invisible. Jump the transition to its end state.
    $page->script('document.querySelectorAll("dialog[open]").forEach((dialog) => dialog.getAnimations({subtree: true}).forEach((animation) => { try { animation.finish() } catch (error) { animation.cancel() } }))');

    $page->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the appointment detail modal is accessible and error free', function () {
    $appointment = Appointment::query()
        ->withoutGlobalScopes()
        ->where('team_id', $this->team->id)
        ->firstOrFail();

    $page = visit(route('appointments.index', ['current_team' => $this->team->slug], absolute: false));

    // Open the first row's action menu, then the detail modal.
    $page->click('@appointment-actions-button')
        ->click('@appointment-view-button')
        ->assertSee($appointment->customer->name);

    // Finish the pending dialog open transition (headless renders no frames).
    $page->script('document.querySelectorAll("dialog[open]").forEach((dialog) => dialog.getAnimations({subtree: true}).forEach((animation) => { try { animation.finish() } catch (error) { animation.cancel() } }))');

    $page->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the calendar day view is accessible and error free', function () {
    visit(route('calendar.index', ['current_team' => $this->team->slug], absolute: false))
        ->assertSee('Calendar')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});
