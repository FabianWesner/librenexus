<?php

use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Availability\ComputeSlots;
use App\Actions\Availability\GetBookableSlots;
use App\Actions\SelfService\RescheduleAppointmentViaToken;
use App\Data\CurrentTenant;
use App\Data\Slot;
use App\Enums\AppointmentStatus;
use App\Enums\TeamRole;
use App\Exceptions\SlotNoLongerAvailableException;
use App\Mail\AppointmentRescheduledMail;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Customer self-service reschedule (FR-CANCEL-3, Epic 08, AC-3): the move
 * is atomic, keeps the same token, queues the reschedule notice with the
 * manage link, and respects the cut-off; the admin path queues the same
 * notice without a link (FR-APPT-5, Epic 07 deferral).
 */
covers(RescheduleAppointmentViaToken::class, RescheduleAppointment::class, AppointmentRescheduledMail::class);

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
    $this->newStart = CarbonImmutable::parse('2027-03-08T11:00:00Z');
    $this->appointment = Appointment::factory()
        ->for($this->team, 'team')->for($this->staff, 'staff')->for($this->service, 'service')->for($this->customer, 'customer')
        ->between('2027-03-08T09:00:00Z', '2027-03-08T10:00:00Z')
        ->create(['cancellation_token_hash' => hash('sha256', 'reschedule-test-token')]);

    $this->travelTo($this->slotStart->subDays(3));
    app(CurrentTenant::class)->set($this->team);
});

test('rescheduling to a free slot moves the appointment and queues the notice with the manage link', function () {
    $originalHash = $this->appointment->cancellation_token_hash;

    $rescheduled = app(RescheduleAppointmentViaToken::class)
        ->handle($this->appointment, $this->newStart, 'reschedule-test-token');

    $fresh = $rescheduled->fresh();

    expect($fresh->starts_at->toIso8601String())->toBe($this->newStart->toIso8601String())
        ->and($fresh->ends_at->toIso8601String())->toBe($this->newStart->addMinutes(60)->toIso8601String())
        ->and($fresh->buffered_starts_at->toIso8601String())->toBe($this->newStart->toIso8601String())
        ->and($fresh->buffered_ends_at->toIso8601String())->toBe($this->newStart->addMinutes(60)->toIso8601String())
        // staff_id can only be confirmed, never changed: RescheduleAppointment
        // pins the engine to the appointment's own staff, so a mutant dropping
        // staff_id from the update is provably equivalent at every call site.
        ->and($fresh->staff_id)->toBe($this->staff->id)
        ->and($fresh->status)->toBe(AppointmentStatus::Confirmed)
        ->and($fresh->cancellation_token_hash)->toBe($originalHash);

    Mail::assertQueued(AppointmentRescheduledMail::class, function (AppointmentRescheduledMail $mail): bool {
        $envelope = $mail->envelope();

        return $mail->hasTo('alice@example.com')
            && $mail->manageUrl === route('booking.manage', ['token' => 'reschedule-test-token'])
            && $envelope->subject === 'Your appointment at '.$this->team->name.' was rescheduled'
            && count($envelope->replyTo) === 1
            && $envelope->replyTo[0]->address === 'desk@clinic.example'
            && $envelope->replyTo[0]->name === $this->team->name
            && str_contains($mail->render(), 'March 8, 2027 at 11:00')
            && str_contains($mail->render(), 'reschedule-test-token');
    });
});

test('the reschedule notice omits the reply-to when the tenant has no contact email', function () {
    $this->team->update(['contact_email' => null]);

    $mail = new AppointmentRescheduledMail($this->appointment->fresh());

    expect($mail->envelope()->replyTo)->toBe([])
        ->and($mail->manageUrl)->toBeNull();
});

test('an occupied target slot yields the friendly error and keeps the original time', function () {
    Appointment::factory()
        ->for($this->team, 'team')->for($this->staff, 'staff')->for($this->service, 'service')
        ->for(Customer::factory()->state(['team_id' => $this->team->id]), 'customer')
        ->between('2027-03-08T11:00:00Z', '2027-03-08T12:00:00Z')
        ->create();

    expect(fn () => app(RescheduleAppointmentViaToken::class)->handle($this->appointment, $this->newStart, 'reschedule-test-token'))
        ->toThrow(SlotNoLongerAvailableException::class, 'no longer available');

    expect($this->appointment->fresh()->starts_at->toIso8601String())->toBe($this->slotStart->toIso8601String());
    Mail::assertNothingQueued();
});

test('rescheduling at or past the cut-off is refused', function (int $minutesBeforeStart) {
    $this->travelTo($this->slotStart->subMinutes($minutesBeforeStart));

    expect(fn () => app(RescheduleAppointmentViaToken::class)->handle($this->appointment, $this->newStart, 'reschedule-test-token'))
        ->toThrow(ValidationException::class);

    expect($this->appointment->fresh()->starts_at->toIso8601String())->toBe($this->slotStart->toIso8601String());
    Mail::assertNothingQueued();
})->with([
    'exactly at the boundary' => [120],
    'past the cut-off' => [45],
]);

test('a terminal appointment cannot be rescheduled via the token', function () {
    $this->appointment->update(['status' => AppointmentStatus::Cancelled]);

    try {
        app(RescheduleAppointmentViaToken::class)->handle($this->appointment, $this->newStart, 'reschedule-test-token');
        $this->fail('Expected the terminal status to refuse the reschedule.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('status')
            ->and($exception->errors()['status'][0])->toContain('Cancelled');
    }

    Mail::assertNothingQueued();
});

test('the manage page reschedules to a selected slot and shows the new time', function () {
    Livewire::test('pages::booking.manage', ['token' => 'reschedule-test-token'])
        ->set('rescheduleDate', '2027-03-08')
        ->call('selectSlot', $this->newStart->toIso8601String())
        ->assertHasNoErrors()
        ->call('reschedule')
        ->assertHasNoErrors()
        ->assertSee('Your appointment has been moved')
        ->assertSee('March 8, 2027 at 11:00');

    expect($this->appointment->fresh()->starts_at->toIso8601String())->toBe($this->newStart->toIso8601String());
});

test('the manage page rejects a slot that is not on the offered list', function () {
    Livewire::test('pages::booking.manage', ['token' => 'reschedule-test-token'])
        ->set('rescheduleDate', '2027-03-08')
        ->call('selectSlot', '2027-03-08T20:00:00+00:00')
        ->assertHasErrors(['selectedSlot']);

    expect($this->appointment->fresh()->starts_at->toIso8601String())->toBe($this->slotStart->toIso8601String());
});

test('confirming without a selected slot is rejected', function () {
    Livewire::test('pages::booking.manage', ['token' => 'reschedule-test-token'])
        ->call('reschedule')
        ->assertHasErrors(['selectedSlot']);
});

test('the admin reschedule path queues the notice without a manage link', function () {
    $admin = User::factory()->create();
    $this->team->members()->attach($admin, ['role' => TeamRole::Owner->value]);
    $this->actingAs($admin);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('openRescheduleModal', $this->appointment->id)
        ->call('rescheduleTo', $this->newStart->toIso8601String())
        ->assertHasNoErrors();

    expect($this->appointment->fresh()->starts_at->toIso8601String())->toBe($this->newStart->toIso8601String());

    Mail::assertQueued(AppointmentRescheduledMail::class, function (AppointmentRescheduledMail $mail): bool {
        return $mail->hasTo('alice@example.com')
            && $mail->manageUrl === null
            && str_contains($mail->render(), 'manage link from your confirmation email');
    });
});

test('a constraint-level lost reschedule race is translated to the friendly slot error', function () {
    // Occupy the target slot for real.
    Appointment::factory()
        ->for($this->team, 'team')->for($this->staff, 'staff')->for($this->service, 'service')
        ->for(Customer::factory()->state(['team_id' => $this->team->id]), 'customer')
        ->between('2027-03-08T11:00:00Z', '2027-03-08T12:00:00Z')
        ->create();

    // An engine that wrongly reports the slot as free simulates the race
    // window after re-validation: the exclusion constraint must be the
    // final arbiter and the 23P01 must surface as the friendly error.
    $blindEngine = new class(app(ComputeSlots::class)) extends GetBookableSlots
    {
        public function handle(
            Team $team,
            Service $service,
            ?Staff $staff,
            string $fromDate,
            string $untilDate,
            ?CarbonImmutable $now = null,
            ?int $excludeAppointmentId = null,
        ): Collection {
            $start = CarbonImmutable::parse($fromDate.'T11:00:00', 'UTC');

            return collect([new Slot(
                staffId: (int) $staff?->id,
                startsAt: $start,
                endsAt: $start->addMinutes(60),
                bufferedStartsAt: $start,
                bufferedEndsAt: $start->addMinutes(60),
            )]);
        }
    };

    $racingAction = new RescheduleAppointment($blindEngine);

    expect(fn () => $racingAction->handle($this->appointment, $this->newStart))
        ->toThrow(SlotNoLongerAvailableException::class, 'no longer available');

    $fresh = $this->appointment->fresh();

    expect($fresh->starts_at->toIso8601String())->toBe($this->slotStart->toIso8601String())
        ->and($fresh->ends_at->toIso8601String())->toBe($this->slotStart->addMinutes(60)->toIso8601String());
});

test('rescheduling resets the reminder so the new time gets its own reminder', function () {
    $this->appointment->update(['reminder_sent_at' => now()]);

    app(RescheduleAppointmentViaToken::class)
        ->handle($this->appointment->refresh(), $this->newStart, 'reschedule-test-token');

    expect($this->appointment->refresh()->reminder_sent_at)->toBeNull();
});
