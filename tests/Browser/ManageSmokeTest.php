<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * The actioned manage page in a real browser (Epic 08, QG-A11Y for the
 * tokened page): accessible, JS-error free, and the cancel and reschedule
 * click-throughs land in the expected states.
 */
beforeEach(function () {
    $this->team = Team::factory()->create([
        'name' => 'Bright Smiles Dental',
        'timezone' => 'Europe/Berlin',
        'contact_email' => 'hello@bright-smiles.test',
        'cancellation_cutoff_minutes' => 120,
    ]);

    $this->staff = Staff::factory()->create(['team_id' => $this->team->id, 'name' => 'Dr. Dana Demo']);

    $this->service = Service::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Checkup',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
    ]);
    $this->service->staff()->attach($this->staff);

    // Open every weekday so reschedule slots always exist, regardless of
    // when the suite runs.
    foreach (range(1, 7) as $weekday) {
        AvailabilityRule::factory()->window($weekday, '00:00', '24:00')->create([
            'team_id' => $this->team->id,
            'staff_id' => $this->staff->id,
        ]);
    }

    $customer = Customer::factory()->create(['team_id' => $this->team->id, 'name' => 'Browser Bob']);

    // Three days out: comfortably before the cut-off in real time.
    $startsAt = CarbonImmutable::now('Europe/Berlin')->addDays(3)->setTime(10, 0)->utc();

    $this->appointment = Appointment::factory()
        ->for($this->team, 'team')->for($this->staff, 'staff')->for($this->service, 'service')->for($customer, 'customer')
        ->between($startsAt->toIso8601String(), $startsAt->addMinutes(30)->toIso8601String())
        ->create(['cancellation_token_hash' => hash('sha256', 'manage-actions-token')]);
});

test('the actioned manage page is accessible and a cancel click-through lands in the cancelled state', function () {
    $page = visit('/manage/manage-actions-token');

    $page->assertSee('Your appointment')
        ->assertSee('Checkup')
        ->assertSee('Browser Bob')
        ->assertSee('Cancel appointment')
        ->assertSee('Move to another time')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();

    $page->click('@manage-cancel-button')
        ->assertSee('Cancel this appointment?')
        ->click('@manage-cancel-confirm')
        ->assertSee('Your appointment has been cancelled')
        ->assertSee('Cancelled')
        ->assertNoJavascriptErrors();

    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

test('a reschedule click-through moves the appointment and renders the new time', function () {
    $originalStart = $this->appointment->starts_at->toIso8601String();

    $page = visit('/manage/manage-actions-token');

    // The first offered slot of the appointment's own day is 00:00 local
    // (the availability window opens at midnight).
    $page->assertSee('Move to another time')
        ->click('@manage-slot-button')
        ->assertSee('Confirm new time')
        ->click('@manage-reschedule-confirm')
        ->assertSee('Your appointment has been moved')
        ->assertSee('at 00:00')
        ->assertNoJavascriptErrors();

    $fresh = $this->appointment->fresh();

    expect($fresh->starts_at->toIso8601String())->not->toBe($originalStart)
        ->and($fresh->starts_at->setTimezone('Europe/Berlin')->format('H:i'))->toBe('00:00')
        ->and($fresh->status)->toBe(AppointmentStatus::Confirmed);
});
