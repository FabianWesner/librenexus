<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;

/**
 * SEC-TOKEN: the manage token is the only credential for the view-only
 * customer page; it resolves exactly one appointment, forged tokens 404,
 * and the confirmation page never renders under a foreign tenant slug.
 */

/**
 * Create a full appointment for a fresh tenant whose manage token is the
 * given raw string (hash stored, raw never persisted).
 */
function appointmentWithToken(string $rawToken, string $serviceName = 'Consultation', string $customerName = 'Alice Example'): Appointment
{
    $team = Team::factory()->create(['timezone' => 'Europe/Berlin']);
    $staff = Staff::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create(['team_id' => $team->id, 'name' => $serviceName]);
    $customer = Customer::factory()->create(['team_id' => $team->id, 'name' => $customerName]);

    return Appointment::factory()
        ->for($team, 'team')->for($staff, 'staff')->for($service, 'service')->for($customer, 'customer')
        ->create(['cancellation_token_hash' => hash('sha256', $rawToken)]);
}

test('a valid raw token resolves the view-only manage page with the appointment details', function () {
    $appointment = appointmentWithToken('valid-raw-token-abc123');

    $this->get(route('booking.manage', ['token' => 'valid-raw-token-abc123']))
        ->assertOk()
        ->assertSee('Your appointment')
        ->assertSee('Consultation')
        ->assertSee('Alice Example')
        ->assertSee($appointment->staff->name)
        ->assertSee('Europe/Berlin')
        ->assertSee('Confirmed');
});

test('a forged token 404s', function () {
    appointmentWithToken('the-real-token');

    $this->get(route('booking.manage', ['token' => 'a-forged-token']))->assertNotFound();
});

test('a token only ever exposes its own appointment', function () {
    appointmentWithToken('token-for-a', 'Service Alpha', 'Customer Alpha');
    appointmentWithToken('token-for-b', 'Service Beta', 'Customer Beta');

    $this->get(route('booking.manage', ['token' => 'token-for-a']))
        ->assertOk()
        ->assertSee('Service Alpha')
        ->assertSee('Customer Alpha')
        ->assertDontSee('Service Beta')
        ->assertDontSee('Customer Beta');
});

test('the confirmation page resolves under its own tenant slug', function () {
    $appointment = appointmentWithToken('confirm-token', 'Service Gamma');

    $this->get(route('booking.confirmed', [
        'tenant' => $appointment->team->slug,
        'token' => 'confirm-token',
    ]))
        ->assertOk()
        ->assertSee('Service Gamma')
        ->assertSee(route('booking.manage', ['token' => 'confirm-token']));
});

test('the confirmation page 404s under a foreign tenant slug', function () {
    appointmentWithToken('cross-tenant-token');
    $otherTeam = Team::factory()->create();

    $this->get(route('booking.confirmed', [
        'tenant' => $otherTeam->slug,
        'token' => 'cross-tenant-token',
    ]))->assertNotFound();
});
