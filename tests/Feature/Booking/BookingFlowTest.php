<?php

use App\Actions\Booking\BookAppointment;
use App\Data\BookingRequest;
use App\Data\CurrentTenant;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Full public booking-flow suite (Epic 06, AC-1/AC-4): happy path, every
 * validation failure, slot refresh after a lost race, honeypot, and the
 * submission throttle (SEC-RATE).
 */
covers(AppointmentConfirmationMail::class);

beforeEach(function () {
    Mail::fake();

    $this->team = Team::factory()->create([
        'name' => 'Flow Clinic',
        'timezone' => 'UTC',
        'contact_email' => 'office@flow-clinic.test',
    ]);
    $this->staff = Staff::factory()->create(['team_id' => $this->team->id, 'name' => 'Erin Example']);
    $this->service = Service::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Consultation',
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
    ]);
    $this->service->staff()->attach($this->staff);

    AvailabilityRule::factory()->window(1, '09:00', '17:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->staff->id,
    ]);

    // 2027-03-08 is a Monday; "now" is the preceding Friday.
    $this->slotStart = CarbonImmutable::parse('2027-03-08T09:00:00', 'UTC');
    $this->travelTo($this->slotStart->subDays(3));
});

/**
 * Drive the component through service, staff, and slot selection.
 */
function bookingComponentAtDetails(Team $team, Service $service, Staff $staff, CarbonImmutable $slotStart): Testable
{
    return Livewire::test('pages::booking.show', ['tenant' => $team->slug])
        ->call('chooseService', $service->id)
        ->call('chooseStaff', (string) $staff->id)
        ->call('selectDate', $slotStart->format('Y-m-d'))
        ->call('chooseSlot', $slotStart->toIso8601String());
}

test('a customer can complete a booking through the whole flow', function () {
    $component = Livewire::test('pages::booking.show', ['tenant' => $this->team->slug])
        ->assertSee('Flow Clinic')
        ->assertSee('Consultation')
        ->call('chooseService', $this->service->id)
        ->assertSet('step', 2)
        ->assertSee('Erin Example')
        ->call('chooseStaff', (string) $this->staff->id)
        ->assertSet('step', 3)
        ->assertSee('Times are shown in UTC time.')
        ->call('selectDate', '2027-03-08')
        ->call('chooseSlot', $this->slotStart->toIso8601String())
        ->assertSet('step', 4)
        ->set('name', 'Alice Example')
        ->set('email', 'Alice@Example.com')
        ->set('phone', '+49 30 1234')
        ->set('notes', 'First visit')
        ->call('submitDetails')
        ->assertSet('step', 5)
        ->assertSee('Confirm your booking')
        ->call('confirmBooking')
        ->assertHasNoErrors();

    $appointment = Appointment::query()->withoutGlobalScopes()->sole();

    expect($appointment->starts_at->equalTo($this->slotStart))->toBeTrue()
        ->and($appointment->staff_id)->toBe($this->staff->id)
        ->and($appointment->notes)->toBe('First visit');

    $rawToken = null;
    Mail::assertQueued(AppointmentConfirmationMail::class, function (AppointmentConfirmationMail $mail) use (&$rawToken): bool {
        $rawToken = Str::afterLast($mail->manageUrl, '/');

        return $mail->hasTo('alice@example.com');
    });

    // The mailed manage link carries the raw token of this appointment.
    expect($rawToken)->not->toBeNull()
        ->and(hash('sha256', (string) $rawToken))->toBe($appointment->cancellation_token_hash);

    $component->assertRedirect(route('booking.confirmed', [
        'tenant' => $this->team->slug,
        'token' => $rawToken,
    ]));
});

test('a missing name fails validation and persists nothing', function () {
    bookingComponentAtDetails($this->team, $this->service, $this->staff, $this->slotStart)
        ->set('name', '')
        ->set('email', 'alice@example.com')
        ->call('submitDetails')
        ->assertHasErrors(['name' => 'required'])
        ->assertSet('step', 4);

    expect(Appointment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('an invalid email fails validation and persists nothing', function () {
    bookingComponentAtDetails($this->team, $this->service, $this->staff, $this->slotStart)
        ->set('name', 'Alice Example')
        ->set('email', 'not-an-email')
        ->call('submitDetails')
        ->assertHasErrors(['email' => 'email']);

    expect(Appointment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('an overlong phone or notes value fails validation', function () {
    bookingComponentAtDetails($this->team, $this->service, $this->staff, $this->slotStart)
        ->set('name', 'Alice Example')
        ->set('email', 'alice@example.com')
        ->set('phone', str_repeat('1', 65))
        ->set('notes', str_repeat('x', 2001))
        ->call('submitDetails')
        ->assertHasErrors(['phone' => 'max', 'notes' => 'max']);
});

test('confirming without a chosen slot fails validation', function () {
    Livewire::test('pages::booking.show', ['tenant' => $this->team->slug])
        ->call('chooseService', $this->service->id)
        ->set('name', 'Alice Example')
        ->set('email', 'alice@example.com')
        ->call('confirmBooking')
        ->assertHasErrors(['selectedSlot']);

    expect(Appointment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a slot that has moved into the past fails validation', function () {
    $component = bookingComponentAtDetails($this->team, $this->service, $this->staff, $this->slotStart)
        ->set('name', 'Alice Example')
        ->set('email', 'alice@example.com');

    $this->travelTo($this->slotStart->addHour());

    $component->call('confirmBooking')
        ->assertHasErrors(['selectedSlot']);

    expect(Appointment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a service of another tenant cannot be chosen', function () {
    $foreignService = Service::factory()->create(['team_id' => Team::factory()->create()->id]);

    Livewire::test('pages::booking.show', ['tenant' => $this->team->slug])
        ->call('chooseService', $foreignService->id);
})->throws(ModelNotFoundException::class);

test('the slot list excludes a just-booked slot on refresh', function () {
    $component = Livewire::test('pages::booking.show', ['tenant' => $this->team->slug])
        ->call('chooseService', $this->service->id)
        ->call('chooseStaff', (string) $this->staff->id)
        ->call('selectDate', '2027-03-08')
        ->assertSee('09:00');

    // Another customer wins the 09:00 slot in the meantime.
    app(CurrentTenant::class)->set($this->team);
    app(BookAppointment::class)->handle($this->team, new BookingRequest(
        serviceId: $this->service->id,
        staffId: $this->staff->id,
        startsAt: $this->slotStart,
        customerName: 'Fast Customer',
        customerEmail: 'fast@example.com',
        customerPhone: null,
        notes: null,
    ));

    $component->call('selectDate', '2027-03-08')
        ->assertSee('10:00')
        ->assertDontSee('09:00');
});

test('losing the race surfaces the slot error and returns to the slot step', function () {
    $component = bookingComponentAtDetails($this->team, $this->service, $this->staff, $this->slotStart)
        ->set('name', 'Alice Example')
        ->set('email', 'alice@example.com');

    app(CurrentTenant::class)->set($this->team);
    app(BookAppointment::class)->handle($this->team, new BookingRequest(
        serviceId: $this->service->id,
        staffId: $this->staff->id,
        startsAt: $this->slotStart,
        customerName: 'Fast Customer',
        customerEmail: 'fast@example.com',
        customerPhone: null,
        notes: null,
    ));

    $component->call('confirmBooking')
        ->assertHasErrors(['selectedSlot'])
        ->assertSet('step', 3);

    expect(Appointment::query()->withoutGlobalScopes()->count())->toBe(1);
    Mail::assertNothingQueued();
});

test('a filled honeypot drops the submission silently', function () {
    bookingComponentAtDetails($this->team, $this->service, $this->staff, $this->slotStart)
        ->set('name', 'Bot Botson')
        ->set('email', 'bot@example.com')
        ->set('website', 'https://spam.example.com')
        ->call('confirmBooking')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect(Appointment::query()->withoutGlobalScopes()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('the eleventh booking attempt within a minute is throttled', function () {
    foreach (range(1, 10) as $attempt) {
        RateLimiter::attempt('booking:127.0.0.1', 10, fn (): bool => true, 60);
    }

    bookingComponentAtDetails($this->team, $this->service, $this->staff, $this->slotStart)
        ->set('name', 'Alice Example')
        ->set('email', 'alice@example.com')
        ->call('confirmBooking')
        ->assertHasErrors(['booking']);

    expect(Appointment::query()->withoutGlobalScopes()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('the sixty-first slot-computation step within a minute is throttled', function () {
    // chooseStaff enters the time step and consumes the first hit of the
    // 60/min step budget (SEC-RATE, Epic 10).
    $component = Livewire::test('pages::booking.show', ['tenant' => $this->team->slug])
        ->call('chooseService', $this->service->id)
        ->call('chooseStaff', (string) $this->staff->id)
        ->assertHasNoErrors();

    foreach (range(2, 60) as $attempt) {
        RateLimiter::attempt('booking-steps:127.0.0.1', 60, fn (): bool => true, 60);
    }

    // The sixty-first step action within the minute errors with a friendly
    // message instead of recomputing slots.
    $component->call('selectDate', '2027-03-08')
        ->assertHasErrors(['booking'])
        ->assertSee('Too many requests. Please wait a minute and try again.');
});

test('an unknown tenant slug 404s the booking page', function () {
    $this->get('/tenant-that-does-not-exist')->assertNotFound();
});
