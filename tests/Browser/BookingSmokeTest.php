<?php

use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;

beforeEach(function () {
    $this->team = Team::factory()->create([
        'name' => 'Bright Smiles Dental',
        'timezone' => 'Europe/Berlin',
        'contact_email' => 'hello@bright-smiles.test',
    ]);

    $this->staff = Staff::factory()->create(['team_id' => $this->team->id, 'name' => 'Dr. Dana Demo']);

    $this->service = Service::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Checkup',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'price_minor' => 5000,
    ]);
    $this->service->staff()->attach($this->staff);

    // Open every weekday so a bookable slot always exists within the lead
    // time, regardless of when the suite runs.
    foreach (range(1, 7) as $weekday) {
        AvailabilityRule::factory()->window($weekday, '00:00', '24:00')->create([
            'team_id' => $this->team->id,
            'staff_id' => $this->staff->id,
        ]);
    }
});

test('a customer can book an appointment through the browser', function () {
    $page = visit('/'.$this->team->slug);

    $page->assertSee('Bright Smiles Dental')
        ->assertSee('Checkup')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();

    $page->click('Checkup')
        ->assertSee('Who should it be with?')
        ->click('Any available')
        ->assertSee('Pick a day and time')
        ->assertSee('Europe/Berlin')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();

    $page->click('@booking-slot-button')
        ->assertSee('Your details')
        ->fill('@booking-name-input', 'Browser Bob')
        ->fill('@booking-email-input', 'bob@example.com')
        ->click('@booking-details-continue')
        ->assertSee('Confirm your booking')
        ->click('@booking-confirm-button')
        ->assertSee('Booking confirmed')
        ->assertSee('Manage your appointment')
        ->assertNoJavascriptErrors();

    $appointment = Appointment::query()->withoutGlobalScopes()->sole();

    expect($appointment->staff_id)->toBe($this->staff->id)
        ->and($appointment->customer()->withoutGlobalScopes()->sole()->email)->toBe('bob@example.com');
});

test('the manage page is accessible and shows the appointment', function () {
    $customer = Customer::factory()->create(['team_id' => $this->team->id, 'name' => 'Browser Bob']);

    Appointment::factory()
        ->for($this->team, 'team')->for($this->staff, 'staff')->for($this->service, 'service')->for($customer, 'customer')
        ->create(['cancellation_token_hash' => hash('sha256', 'browser-manage-token')]);

    visit('/manage/browser-manage-token')
        ->assertSee('Your appointment')
        ->assertSee('Checkup')
        ->assertSee('Browser Bob')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});
