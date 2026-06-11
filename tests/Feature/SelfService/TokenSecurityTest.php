<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * SEC-TOKEN for the actioned manage page (Epic 08, AC-1): the token is the
 * only credential, it acts on exactly one appointment, forged tokens 404,
 * terminal appointments refuse changes, and the raw token never reaches
 * the logs (SEC-TOKEN-2).
 */

/**
 * A full self-service scenario for a fresh tenant: future appointment on
 * 2027-03-08 (a Monday) at 09:00 UTC whose manage token hash matches the
 * given raw token; "now" is three days earlier.
 */
function tokenSecurityScenario(string $rawToken, string $serviceName = 'Consultation', string $customerName = 'Alice Example'): Appointment
{
    $team = Team::factory()->create(['timezone' => 'UTC']);
    $staff = Staff::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'team_id' => $team->id,
        'name' => $serviceName,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
    ]);
    $service->staff()->attach($staff);
    $customer = Customer::factory()->create(['team_id' => $team->id, 'name' => $customerName]);

    AvailabilityRule::factory()->window(1, '09:00', '17:00')->create([
        'team_id' => $team->id,
        'staff_id' => $staff->id,
    ]);

    return Appointment::factory()
        ->for($team, 'team')->for($staff, 'staff')->for($service, 'service')->for($customer, 'customer')
        ->between('2027-03-08T09:00:00Z', '2027-03-08T10:00:00Z')
        ->create(['cancellation_token_hash' => hash('sha256', $rawToken)]);
}

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-05T09:00:00Z'));
});

test('a valid token resolves the manage page with details and actions', function () {
    $appointment = tokenSecurityScenario('valid-action-token');

    $this->get(route('booking.manage', ['token' => 'valid-action-token']))
        ->assertOk()
        ->assertSee('Your appointment')
        ->assertSee('Consultation')
        ->assertSee('Alice Example')
        ->assertSee($appointment->staff->name)
        ->assertSee('Cancel appointment')
        ->assertSee('Move to another time');
});

test('a forged or tampered token 404s', function () {
    tokenSecurityScenario('the-real-token');

    $this->get(route('booking.manage', ['token' => 'a-forged-token']))->assertNotFound();
    $this->get(route('booking.manage', ['token' => 'the-real-tokeN']))->assertNotFound();
});

test('a token for appointment A can never act on appointment B', function () {
    $appointmentA = tokenSecurityScenario('token-for-a', 'Service Alpha', 'Customer Alpha');
    $appointmentB = tokenSecurityScenario('token-for-b', 'Service Beta', 'Customer Beta');

    Livewire::test('pages::booking.manage', ['token' => 'token-for-a'])
        ->assertSee('Service Alpha')
        ->assertDontSee('Service Beta')
        ->call('cancel')
        ->assertHasNoErrors();

    expect($appointmentA->fresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($appointmentB->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

test('a terminal appointment refuses self-service cancellation', function () {
    $appointment = tokenSecurityScenario('terminal-token');
    $appointment->update(['status' => AppointmentStatus::Cancelled]);

    Livewire::test('pages::booking.manage', ['token' => 'terminal-token'])
        ->call('cancel')
        ->assertHasErrors(['cancel']);

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

test('the raw token never appears in the application log after a full cancel flow', function () {
    $rawToken = 'log-leak-probe-token-1234567890abcdef';
    tokenSecurityScenario($rawToken);

    $logDirectory = storage_path('logs');
    File::ensureDirectoryExists($logDirectory);
    collect(File::files($logDirectory))->each(fn ($file) => File::put($file->getPathname(), ''));

    $this->get(route('booking.manage', ['token' => $rawToken]))->assertOk();

    Livewire::test('pages::booking.manage', ['token' => $rawToken])
        ->call('cancel')
        ->assertHasNoErrors();

    foreach (File::files($logDirectory) as $file) {
        expect(File::get($file->getPathname()))->not->toContain($rawToken);
    }
});
