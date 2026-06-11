<?php

use App\Actions\Booking\BookAppointment;
use App\Data\BookingRequest;
use App\Data\CurrentTenant;
use App\Enums\AppointmentStatus;
use App\Enums\TeamRole;
use App\Mail\AppointmentCancellationMail;
use App\Mail\AppointmentConfirmationMail;
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
 * Manual create, reschedule, and cancel through the appointments page
 * (Epic 07, FR-APPT-3, AC-3/AC-5): the same conflict guarantee as public
 * booking, atomic rescheduling, the cancellation email (FR-APPT-5), and
 * the staff-role own-record restriction (FR-APPT-2).
 */
beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->staffUser = User::factory()->create();

    $this->team = Team::factory()->create(['timezone' => 'UTC']);
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->staffUser, ['role' => TeamRole::Staff->value]);

    $staffMembership = $this->team->memberships()->where('user_id', $this->staffUser->id)->firstOrFail();

    $this->ownStaff = Staff::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Olivia Own',
        'membership_id' => $staffMembership->id,
    ]);
    $this->otherStaff = Staff::factory()->create(['team_id' => $this->team->id, 'name' => 'Otto Other']);

    $this->service = Service::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Consultation',
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
    ]);
    $this->service->staff()->attach([$this->ownStaff->id, $this->otherStaff->id]);

    foreach ([$this->ownStaff, $this->otherStaff] as $staffMember) {
        AvailabilityRule::factory()->window(1, '09:00', '17:00')->create([
            'team_id' => $this->team->id,
            'staff_id' => $staffMember->id,
        ]);
    }

    // 2027-03-08 is a Monday; "now" is the preceding Friday.
    $this->slotStart = CarbonImmutable::parse('2027-03-08T09:00:00', 'UTC');
    $this->travelTo($this->slotStart->subDays(3));

    app(CurrentTenant::class)->set($this->team);
});

/**
 * Book a slot through the same action the public flow uses.
 */
function manualBookingOccupy(Team $team, Service $service, Staff $staff, CarbonImmutable $startsAt): Appointment
{
    return app(BookAppointment::class)->handle($team, new BookingRequest(
        serviceId: $service->id,
        staffId: $staff->id,
        startsAt: $startsAt,
        customerName: 'Existing Customer',
        customerEmail: 'existing@example.com',
        customerPhone: null,
        notes: null,
    ))->appointment;
}

test('an admin can create an appointment and the customer record is deduplicated', function () {
    $existingCustomer = Customer::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'casey@example.com',
        'name' => 'Old Name',
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('openCreateForm')
        ->set('customerName', 'Casey Customer')
        ->set('customerEmail', 'Casey@Example.com')
        ->set('customerPhone', '+49 151 1234567')
        ->set('newServiceId', $this->service->id)
        ->set('newStaffId', $this->otherStaff->id)
        ->set('newDate', '2027-03-08')
        ->call('selectNewSlot', $this->slotStart->toIso8601String())
        ->call('createAppointment')
        ->assertHasNoErrors();

    $appointment = Appointment::query()->firstOrFail();

    expect($appointment)
        ->staff_id->toBe($this->otherStaff->id)
        ->service_id->toBe($this->service->id)
        ->customer_id->toBe($existingCustomer->id)
        ->status->toBe(AppointmentStatus::Confirmed)
        ->and($appointment->starts_at->equalTo($this->slotStart))->toBeTrue()
        ->and(Customer::query()->count())->toBe(1)
        ->and($existingCustomer->fresh()->name)->toBe('Casey Customer');

    Mail::assertQueued(AppointmentConfirmationMail::class, fn (AppointmentConfirmationMail $mail): bool => $mail->hasTo('casey@example.com'));
});

test('a staff-role member can create an appointment for their own staff record', function () {
    $this->actingAs($this->staffUser);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('openCreateForm')
        ->assertSet('newStaffId', $this->ownStaff->id)
        ->set('customerName', 'Casey Customer')
        ->set('customerEmail', 'casey@example.com')
        ->set('newServiceId', $this->service->id)
        ->set('newStaffId', $this->ownStaff->id)
        ->set('newDate', '2027-03-08')
        ->call('selectNewSlot', $this->slotStart->toIso8601String())
        ->call('createAppointment')
        ->assertHasNoErrors();

    expect(Appointment::query()->firstOrFail()->staff_id)->toBe($this->ownStaff->id);
});

test('a staff-role member cannot create an appointment for another staff member', function () {
    $this->actingAs($this->staffUser);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->set('customerName', 'Casey Customer')
        ->set('customerEmail', 'casey@example.com')
        ->set('newServiceId', $this->service->id)
        ->set('newStaffId', $this->otherStaff->id)
        ->set('newDate', '2027-03-08')
        ->call('selectNewSlot', $this->slotStart->toIso8601String())
        ->call('createAppointment')
        ->assertHasErrors(['newStaffId']);

    expect(Appointment::query()->count())->toBe(0);
});

test('a member without any appointment permissions cannot open the create form', function () {
    $unlinkedUser = User::factory()->create();
    $this->team->members()->attach($unlinkedUser, ['role' => TeamRole::Staff->value]);

    $this->actingAs($unlinkedUser);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('openCreateForm')
        ->assertForbidden();
});

test('creating on a slot that was taken in the meantime shows the friendly error', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->set('customerName', 'Casey Customer')
        ->set('customerEmail', 'casey@example.com')
        ->set('newServiceId', $this->service->id)
        ->set('newStaffId', $this->otherStaff->id)
        ->set('newDate', '2027-03-08')
        ->call('selectNewSlot', $this->slotStart->toIso8601String());

    // Another booking wins the race for the same slot after selection.
    manualBookingOccupy($this->team, $this->service, $this->otherStaff, $this->slotStart);

    $component->call('createAppointment')
        ->assertHasErrors(['newSlot']);

    expect(Appointment::query()->count())->toBe(1);
});

test('rescheduling moves the appointment atomically and keeps its identity', function () {
    $appointment = manualBookingOccupy($this->team, $this->service, $this->ownStaff, $this->slotStart);
    $originalTokenHash = $appointment->cancellation_token_hash;
    $newStart = $this->slotStart->setTime(11, 0);

    $this->actingAs($this->admin);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('openRescheduleModal', $appointment->id)
        ->assertSet('rescheduleDate', '2027-03-08')
        ->call('rescheduleTo', $newStart->toIso8601String())
        ->assertHasNoErrors();

    $moved = $appointment->fresh();

    expect($moved->starts_at->equalTo($newStart))->toBeTrue()
        ->and($moved->ends_at->equalTo($newStart->addHour()))->toBeTrue()
        ->and($moved->cancellation_token_hash)->toBe($originalTokenHash)
        ->and(Appointment::query()->count())->toBe(1);
});

test('rescheduling onto an occupied slot fails and keeps the original time', function () {
    $appointment = manualBookingOccupy($this->team, $this->service, $this->ownStaff, $this->slotStart);
    $blocker = manualBookingOccupy($this->team, $this->service, $this->ownStaff, $this->slotStart->setTime(10, 0));

    $this->actingAs($this->admin);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('openRescheduleModal', $appointment->id)
        ->call('rescheduleTo', $this->slotStart->setTime(10, 0)->toIso8601String())
        ->assertHasErrors(['rescheduleSlot']);

    expect($appointment->fresh()->starts_at->equalTo($this->slotStart))->toBeTrue()
        ->and($blocker->fresh()->starts_at->equalTo($this->slotStart->setTime(10, 0)))->toBeTrue();
});

test('a cancelled appointment cannot be rescheduled', function () {
    $appointment = manualBookingOccupy($this->team, $this->service, $this->ownStaff, $this->slotStart);
    $appointment->update(['status' => AppointmentStatus::Cancelled]);

    $this->actingAs($this->admin);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('openRescheduleModal', $appointment->id)
        ->call('rescheduleTo', $this->slotStart->setTime(11, 0)->toIso8601String())
        ->assertHasErrors(['status']);

    expect($appointment->fresh()->starts_at->equalTo($this->slotStart))->toBeTrue();
});

test('cancelling transitions the appointment and queues the cancellation email', function () {
    $appointment = manualBookingOccupy($this->team, $this->service, $this->ownStaff, $this->slotStart);

    $this->actingAs($this->admin);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('openCancelModal', $appointment->id)
        ->call('confirmCancel')
        ->assertHasNoErrors();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);

    Mail::assertQueued(AppointmentCancellationMail::class, fn (AppointmentCancellationMail $mail): bool => $mail->hasTo('existing@example.com'));
});

test('a staff-role member cannot act on another staff member\'s appointment', function () {
    $appointment = manualBookingOccupy($this->team, $this->service, $this->otherStaff, $this->slotStart);

    $this->actingAs($this->staffUser);

    $component = fn () => Livewire::test('pages::appointments.index', ['current_team' => $this->team]);

    $component()->call('openDetail', $appointment->id)->assertForbidden();
    $component()->call('transitionStatus', $appointment->id, AppointmentStatus::Cancelled->value)->assertForbidden();
    $component()->call('openRescheduleModal', $appointment->id)->assertForbidden();
    $component()->call('openCancelModal', $appointment->id)->assertForbidden();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed);
    Mail::assertNotQueued(AppointmentCancellationMail::class);
});

test('a staff-role member can manage their own appointment', function () {
    $appointment = manualBookingOccupy($this->team, $this->service, $this->ownStaff, $this->slotStart);

    $this->actingAs($this->staffUser);

    Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->call('openRescheduleModal', $appointment->id)
        ->call('rescheduleTo', $this->slotStart->setTime(13, 0)->toIso8601String())
        ->assertHasNoErrors();

    expect($appointment->fresh()->starts_at->equalTo($this->slotStart->setTime(13, 0)))->toBeTrue();
});
