<?php

use App\Actions\Booking\BookAppointment;
use App\Data\BookingRequest;
use App\Data\CurrentTenant;
use App\Enums\AppointmentStatus;
use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * The appointment status lifecycle via the appointments page (Epic 07,
 * FR-APPT-4, AC-4/AC-5): every allowed transition succeeds, invalid ones
 * are rejected server-side, and cancelling frees the slot (FR-CANCEL-4).
 */
beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['timezone' => 'UTC']);
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);

    $this->staff = Staff::factory()->create(['team_id' => $this->team->id]);
    $this->service = Service::factory()->create([
        'team_id' => $this->team->id,
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

    app(CurrentTenant::class)->set($this->team);
    $this->actingAs($this->owner);
});

/**
 * An appointment of this tenant in the given status.
 */
function transitionAppointment(Team $team, Staff $staff, Service $service, AppointmentStatus $status): Appointment
{
    return Appointment::factory()
        ->for($team, 'team')->for($staff, 'staff')->for($service, 'service')
        ->for(Customer::factory()->state(['team_id' => $team->id]), 'customer')
        ->between('2027-03-08T09:00:00Z', '2027-03-08T10:00:00Z')
        ->status($status)
        ->create();
}

test('every allowed transition succeeds through the component', function (AppointmentStatus $from, AppointmentStatus $to) {
    $appointment = transitionAppointment($this->team, $this->staff, $this->service, $from);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('transitionStatus', $appointment->id, $to->value)
        ->assertHasNoErrors();

    expect($appointment->fresh()->status)->toBe($to);
})->with([
    'pending to confirmed' => [AppointmentStatus::Pending, AppointmentStatus::Confirmed],
    'pending to cancelled' => [AppointmentStatus::Pending, AppointmentStatus::Cancelled],
    'pending to no-show' => [AppointmentStatus::Pending, AppointmentStatus::NoShow],
    'confirmed to completed' => [AppointmentStatus::Confirmed, AppointmentStatus::Completed],
    'confirmed to cancelled' => [AppointmentStatus::Confirmed, AppointmentStatus::Cancelled],
    'confirmed to no-show' => [AppointmentStatus::Confirmed, AppointmentStatus::NoShow],
]);

test('invalid transitions are rejected server-side with a validation message', function (AppointmentStatus $from, AppointmentStatus $to) {
    $appointment = transitionAppointment($this->team, $this->staff, $this->service, $from);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('transitionStatus', $appointment->id, $to->value)
        ->assertHasErrors(['status']);

    expect($appointment->fresh()->status)->toBe($from);
})->with([
    'completed to confirmed' => [AppointmentStatus::Completed, AppointmentStatus::Confirmed],
    'cancelled to confirmed' => [AppointmentStatus::Cancelled, AppointmentStatus::Confirmed],
    'no-show to pending' => [AppointmentStatus::NoShow, AppointmentStatus::Pending],
    'pending to completed' => [AppointmentStatus::Pending, AppointmentStatus::Completed],
]);

test('an unknown status value is rejected', function () {
    $appointment = transitionAppointment($this->team, $this->staff, $this->service, AppointmentStatus::Pending);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('transitionStatus', $appointment->id, 'not-a-status')
        ->assertHasErrors(['status']);

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Pending);
});

test('cancelling frees the slot so it can be booked again', function () {
    $appointment = app(BookAppointment::class)->handle($this->team, new BookingRequest(
        serviceId: $this->service->id,
        staffId: $this->staff->id,
        startsAt: $this->slotStart,
        customerName: 'First Customer',
        customerEmail: 'first@example.com',
        customerPhone: null,
        notes: null,
    ))->appointment;

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('transitionStatus', $appointment->id, AppointmentStatus::Cancelled->value)
        ->assertHasNoErrors();

    $rebooked = app(BookAppointment::class)->handle($this->team, new BookingRequest(
        serviceId: $this->service->id,
        staffId: $this->staff->id,
        startsAt: $this->slotStart,
        customerName: 'Second Customer',
        customerEmail: 'second@example.com',
        customerPhone: null,
        notes: null,
    ))->appointment;

    expect($rebooked->starts_at->equalTo($this->slotStart))->toBeTrue()
        ->and($rebooked->id)->not->toBe($appointment->id)
        ->and(Appointment::query()->count())->toBe(2);
});
