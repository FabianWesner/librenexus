<?php

use App\Actions\Booking\BookAppointment;
use App\Actions\SelfService\CancelAppointmentViaToken;
use App\Actions\SelfService\EnsureWithinCancellationCutoff;
use App\Data\BookingRequest;
use App\Data\CurrentTenant;
use App\Enums\AppointmentStatus;
use App\Mail\AppointmentCancellationMail;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Customer self-service cancellation (FR-CANCEL-1/2/4, Epic 08, AC-2):
 * cancelling via the token works inside the cut-off window, frees the slot
 * for rebooking, queues the branded cancellation mail, and is refused at
 * and past the cut-off with a clear message. Cancellation-token code is
 * critical logic (test-plan.md), hence the explicit coverage targets.
 */
covers(CancelAppointmentViaToken::class, EnsureWithinCancellationCutoff::class, AppointmentCancellationMail::class);

beforeEach(function () {
    Mail::fake();

    $this->team = Team::factory()->create([
        'timezone' => 'UTC',
        'cancellation_cutoff_minutes' => 120,
        'contact_email' => 'desk@clinic.example',
    ]);
    $this->staff = Staff::factory()->create(['team_id' => $this->team->id]);
    $this->service = Service::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Checkup',
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
    ]);
    $this->service->staff()->attach($this->staff);
    $this->customer = Customer::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'alice@example.com',
    ]);

    AvailabilityRule::factory()->window(1, '09:00', '17:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->staff->id,
    ]);

    // 2027-03-08 is a Monday; the appointment starts at 09:00 UTC.
    $this->slotStart = CarbonImmutable::parse('2027-03-08T09:00:00Z');
    $this->appointment = Appointment::factory()
        ->for($this->team, 'team')->for($this->staff, 'staff')->for($this->service, 'service')->for($this->customer, 'customer')
        ->between('2027-03-08T09:00:00Z', '2027-03-08T10:00:00Z')
        ->create(['cancellation_token_hash' => hash('sha256', 'cancel-test-token')]);

    $this->travelTo($this->slotStart->subDays(3));
    app(CurrentTenant::class)->set($this->team);
});

test('cancelling before the cut-off cancels, frees the slot, and queues the cancellation mail', function () {
    app(CancelAppointmentViaToken::class)->handle($this->appointment);

    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);

    Mail::assertQueued(AppointmentCancellationMail::class, fn (AppointmentCancellationMail $mail): bool => $mail->hasTo('alice@example.com'));

    // FR-CANCEL-4: the freed slot is immediately bookable again.
    $rebooked = app(BookAppointment::class)->handle($this->team, new BookingRequest(
        serviceId: $this->service->id,
        staffId: $this->staff->id,
        startsAt: $this->slotStart,
        customerName: 'Bob Next',
        customerEmail: 'bob@example.com',
        customerPhone: null,
        notes: null,
    ));

    expect($rebooked->appointment->starts_at->toIso8601String())->toBe($this->slotStart->toIso8601String());
});

test('cancellation exactly at the cut-off boundary is refused', function () {
    $this->travelTo($this->slotStart->subMinutes(120));

    expect(fn () => app(CancelAppointmentViaToken::class)->handle($this->appointment))
        ->toThrow(ValidationException::class);

    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed);
    Mail::assertNothingQueued();
});

test('cancellation past the cut-off is refused with a message naming the window', function () {
    $this->travelTo($this->slotStart->subMinutes(60));

    try {
        app(CancelAppointmentViaToken::class)->handle($this->appointment);
        $this->fail('Expected the cut-off to refuse the cancellation.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['cutoff'][0])
            ->toContain('2 hours')
            ->toContain($this->team->name);
    }

    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

test('one minute before the cut-off the cancellation still succeeds', function () {
    $this->travelTo($this->slotStart->subMinutes(121));

    app(CancelAppointmentViaToken::class)->handle($this->appointment);

    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

test('a terminal appointment is rejected before the cut-off is even considered', function () {
    $this->appointment->update(['status' => AppointmentStatus::NoShow]);

    try {
        app(CancelAppointmentViaToken::class)->handle($this->appointment);
        $this->fail('Expected the terminal status to refuse the cancellation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('cancel')
            ->and($exception->errors()['cancel'][0])->toContain('no-show');
    }
});

test('the manage page renders a disabled cancel button with the reason past the cut-off', function () {
    $this->travelTo($this->slotStart->subMinutes(30));

    Livewire::test('pages::booking.manage', ['token' => 'cancel-test-token'])
        ->assertSeeHtml('data-test="manage-cancel-disabled"')
        ->assertSee('Online changes are closed')
        ->assertDontSeeHtml('data-test="manage-cancel-button"')
        ->assertDontSeeHtml('data-test="manage-reschedule-confirm"');
});

test('the manage page cancel action cancels and shows the cancelled state', function () {
    Livewire::test('pages::booking.manage', ['token' => 'cancel-test-token'])
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertSee('Your appointment has been cancelled')
        ->assertSee('Cancelled');

    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
    Mail::assertQueued(AppointmentCancellationMail::class);
});

test('the cancellation mail body is branded with team, service, and local time', function () {
    $berlinTeam = Team::factory()->create([
        'timezone' => 'Europe/Berlin',
        'name' => 'Branding Clinic',
        'contact_email' => 'desk@clinic.example',
    ]);
    app(CurrentTenant::class)->set($berlinTeam);

    $staff = Staff::factory()->create(['team_id' => $berlinTeam->id, 'name' => 'Dr. Maila Render']);
    $service = Service::factory()->create(['team_id' => $berlinTeam->id, 'name' => 'Render Checkup']);
    $customer = Customer::factory()->create(['team_id' => $berlinTeam->id, 'name' => 'Carla Customer']);

    $appointment = Appointment::factory()
        ->for($berlinTeam, 'team')->for($staff, 'staff')->for($service, 'service')->for($customer, 'customer')
        ->between('2027-03-08T09:00:00Z', '2027-03-08T10:00:00Z')
        ->create();

    $mail = new AppointmentCancellationMail($appointment);
    $rendered = $mail->render();
    $envelope = $mail->envelope();

    // 09:00 UTC is 10:00 in Berlin.
    expect($rendered)->toContain('Branding Clinic')
        ->toContain('Render Checkup')
        ->toContain('Dr. Maila Render')
        ->toContain('March 8, 2027 at 10:00')
        ->toContain('Europe/Berlin')
        ->and($envelope->subject)->toContain('Branding Clinic')
        ->and($envelope->replyTo)->toHaveCount(1)
        ->and($envelope->replyTo[0]->address)->toBe('desk@clinic.example')
        ->and($envelope->replyTo[0]->name)->toBe('Branding Clinic');

    // Without a tenant contact email there is no reply-to.
    $berlinTeam->update(['contact_email' => null]);
    $plainMail = new AppointmentCancellationMail($appointment->fresh());

    expect($plainMail->envelope()->replyTo)->toBe([]);
});

test('the throttle refuses the 21st mutating action in a minute with a clear message', function () {
    foreach (range(1, 20) as $unused) {
        RateLimiter::attempt('manage-actions:127.0.0.1', 20, fn (): bool => true, 60);
    }

    Livewire::test('pages::booking.manage', ['token' => 'cancel-test-token'])
        ->call('cancel')
        ->assertHasErrors(['notice'])
        ->assertSee('Too many requests');

    expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});
