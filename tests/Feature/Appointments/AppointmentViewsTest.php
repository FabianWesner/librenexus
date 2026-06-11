<?php

use App\Data\CurrentTenant;
use App\Enums\AppointmentStatus;
use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Appointment list and calendar views (Epic 07, AC-1/AC-2): role-based
 * visibility is enforced in the query itself (FR-APPT-2), every filter
 * works (FR-APPT-1), and neither view grows its query count with the
 * number of appointments (NFR-PERF).
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->staffUser = User::factory()->create();

    $this->team = Team::factory()->create(['timezone' => 'UTC']);
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->staffUser, ['role' => TeamRole::Staff->value]);

    $this->owner->switchTeam($this->team);

    $staffMembership = $this->team->memberships()->where('user_id', $this->staffUser->id)->firstOrFail();

    $this->ownStaff = Staff::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Olivia Own',
        'membership_id' => $staffMembership->id,
    ]);
    $this->otherStaff = Staff::factory()->create(['team_id' => $this->team->id, 'name' => 'Otto Other']);

    $this->serviceA = Service::factory()->create(['team_id' => $this->team->id, 'name' => 'Checkup']);
    $this->serviceB = Service::factory()->create(['team_id' => $this->team->id, 'name' => 'Cleaning']);

    app(CurrentTenant::class)->set($this->team);
});

/**
 * An appointment for the given staff and service on a fixed future day.
 */
function viewsAppointment(Team $team, Staff $staff, Service $service, string $startsAt, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
{
    return Appointment::factory()
        ->for($team, 'team')->for($staff, 'staff')->for($service, 'service')
        ->for(Customer::factory()->state(['team_id' => $team->id]), 'customer')
        ->between($startsAt, CarbonImmutable::parse($startsAt)->addHour()->toIso8601String())
        ->status($status)
        ->create();
}

test('the list shows the tenant appointments with customer, service, and staff data', function () {
    $appointment = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(2)->setTime(9, 0)->toIso8601String());

    $this->actingAs($this->owner)
        ->get(route('appointments.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertSee($appointment->customer->name)
        ->assertSee('Checkup')
        ->assertSee('Olivia Own');
});

test('a staff-role member sees only the appointments of their own staff record', function () {
    $own = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(2)->setTime(9, 0)->toIso8601String());
    $foreign = viewsAppointment($this->team, $this->otherStaff, $this->serviceA, now()->addDays(2)->setTime(11, 0)->toIso8601String());

    $this->actingAs($this->staffUser);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team]);

    // Server-proof: the restriction lives in the query, not just the UI.
    expect($component->instance()->appointments->modelKeys())->toBe([$own->id]);

    $component
        ->assertSee($own->customer->name)
        ->assertDontSee($foreign->customer->name);
});

test('a staff-role member without a linked staff record sees no appointments', function () {
    viewsAppointment($this->team, $this->otherStaff, $this->serviceA, now()->addDays(2)->setTime(9, 0)->toIso8601String());

    $unlinkedUser = User::factory()->create();
    $this->team->members()->attach($unlinkedUser, ['role' => TeamRole::Staff->value]);

    $this->actingAs($unlinkedUser);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team]);

    expect($component->instance()->appointments)->toBeEmpty();
});

test('an admin sees the appointments of every staff member', function () {
    $own = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(2)->setTime(9, 0)->toIso8601String());
    $foreign = viewsAppointment($this->team, $this->otherStaff, $this->serviceA, now()->addDays(2)->setTime(11, 0)->toIso8601String());

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team]);

    expect($component->instance()->appointments->modelKeys())->toBe([$own->id, $foreign->id]);
});

test('the staff filter narrows the list to one staff member', function () {
    viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(2)->setTime(9, 0)->toIso8601String());
    $other = viewsAppointment($this->team, $this->otherStaff, $this->serviceA, now()->addDays(2)->setTime(11, 0)->toIso8601String());

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->set('staffFilter', (string) $this->otherStaff->id);

    expect($component->instance()->appointments->modelKeys())->toBe([$other->id]);
});

test('the service filter narrows the list to one service', function () {
    viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(2)->setTime(9, 0)->toIso8601String());
    $cleaning = viewsAppointment($this->team, $this->ownStaff, $this->serviceB, now()->addDays(2)->setTime(11, 0)->toIso8601String());

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->set('serviceFilter', (string) $this->serviceB->id);

    expect($component->instance()->appointments->modelKeys())->toBe([$cleaning->id]);
});

test('the status filter narrows the list to one status', function () {
    viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(2)->setTime(9, 0)->toIso8601String());
    $cancelled = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(2)->setTime(11, 0)->toIso8601String(), AppointmentStatus::Cancelled);

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->set('statusFilter', AppointmentStatus::Cancelled->value);

    expect($component->instance()->appointments->modelKeys())->toBe([$cancelled->id]);
});

test('the date range filter bounds the list on both ends', function () {
    $early = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(2)->setTime(9, 0)->toIso8601String());
    $middle = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(5)->setTime(9, 0)->toIso8601String());
    $late = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(9)->setTime(9, 0)->toIso8601String());

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team])
        ->set('fromDate', now()->addDays(4)->format('Y-m-d'))
        ->set('untilDate', now()->addDays(6)->format('Y-m-d'));

    expect($component->instance()->appointments->modelKeys())->toBe([$middle->id]);
});

test('the default date range starts today and hides past appointments', function () {
    $past = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->subDays(3)->setTime(9, 0)->toIso8601String());
    $future = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(3)->setTime(9, 0)->toIso8601String());

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team]);

    expect($component->instance()->appointments->modelKeys())->toBe([$future->id]);
});

test('the appointments list paginates at 25 rows and the second page shows the rest', function () {
    foreach (range(0, 29) as $index) {
        viewsAppointment(
            $this->team,
            $this->ownStaff,
            $this->serviceA,
            now()->addDays(2 + intdiv($index, 10))->setTime(8 + ($index % 10), 0)->toIso8601String(),
        );
    }

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.index', ['current_team' => $this->team]);

    expect($component->instance()->appointments->count())->toBe(25)
        ->and($component->instance()->appointments->total())->toBe(30);

    $component->call('gotoPage', 2);

    expect($component->instance()->appointments->count())->toBe(5)
        ->and($component->instance()->appointments->currentPage())->toBe(2);

    // Changing a filter jumps back to the first page.
    $component->set('serviceFilter', (string) $this->serviceA->id);

    expect($component->instance()->appointments->currentPage())->toBe(1);
});

describe('query counts (NFR-PERF)', function () {
    beforeEach(function () {
        $this->queries = 0;
        DB::listen(function () {
            $this->queries++;
        });
    });

    test('the appointments list query count does not grow with the number of appointments', function () {
        viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->addDays(2)->setTime(9, 0)->toIso8601String());

        $this->queries = 0;

        $this->actingAs($this->owner)
            ->get(route('appointments.index', ['current_team' => $this->team->slug]))
            ->assertOk();

        $withOneAppointment = $this->queries;

        foreach (range(9, 15) as $hour) {
            viewsAppointment($this->team, $this->otherStaff, $this->serviceB, now()->addDays(3)->setTime($hour, 0)->toIso8601String());
        }

        $this->queries = 0;

        $this->actingAs($this->owner)
            ->get(route('appointments.index', ['current_team' => $this->team->slug]))
            ->assertOk();

        expect($this->queries)->toBe($withOneAppointment);
    });

    test('the calendar query count does not grow with the number of appointments', function () {
        viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->setTime(9, 0)->toIso8601String());

        $this->queries = 0;

        $this->actingAs($this->owner)
            ->get(route('calendar.index', ['current_team' => $this->team->slug]))
            ->assertOk();

        $withOneAppointment = $this->queries;

        foreach (range(10, 16) as $hour) {
            viewsAppointment($this->team, $this->otherStaff, $this->serviceB, now()->setTime($hour, 0)->toIso8601String());
        }

        $this->queries = 0;

        $this->actingAs($this->owner)
            ->get(route('calendar.index', ['current_team' => $this->team->slug]))
            ->assertOk();

        expect($this->queries)->toBe($withOneAppointment);
    });
});

test('the calendar shows admin columns for all bookable staff and blocks for the day', function () {
    $appointment = viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->setTime(9, 0)->toIso8601String());

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.calendar', ['current_team' => $this->team]);

    $columns = $component->instance()->dayColumns;

    expect(array_column($columns, 'staff'))->toHaveCount(2)
        ->and($columns[0]['staff']->id)->toBe($this->ownStaff->id)
        ->and($columns[0]['blocks'])->toHaveCount(1)
        ->and($columns[0]['blocks'][0]['id'])->toBe($appointment->id)
        ->and($columns[0]['blocks'][0]['customerName'])->toBe($appointment->customer->name)
        ->and($columns[1]['blocks'])->toBeEmpty();
});

test('the calendar shows a staff-role member only their own column', function () {
    viewsAppointment($this->team, $this->otherStaff, $this->serviceA, now()->setTime(9, 0)->toIso8601String());

    $this->actingAs($this->staffUser);

    $component = Livewire::test('pages::appointments.calendar', ['current_team' => $this->team]);

    $columns = $component->instance()->dayColumns;

    expect($columns)->toHaveCount(1)
        ->and($columns[0]['staff']->id)->toBe($this->ownStaff->id);
});

test('the calendar does not draw cancelled or no-show appointments', function () {
    viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->setTime(9, 0)->toIso8601String(), AppointmentStatus::Cancelled);
    viewsAppointment($this->team, $this->ownStaff, $this->serviceA, now()->setTime(11, 0)->toIso8601String(), AppointmentStatus::NoShow);

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::appointments.calendar', ['current_team' => $this->team]);

    expect($component->instance()->dayColumns[0]['blocks'])->toBeEmpty();
});

test('the calendar day navigation moves by one day and back to today', function () {
    $this->actingAs($this->admin);

    $today = now('UTC')->format('Y-m-d');

    Livewire::test('pages::appointments.calendar', ['current_team' => $this->team])
        ->assertSet('day', $today)
        ->call('goToNextDay')
        ->assertSet('day', now('UTC')->addDay()->format('Y-m-d'))
        ->call('goToPreviousDay')
        ->assertSet('day', $today)
        ->call('goToPreviousDay')
        ->assertSet('day', now('UTC')->subDay()->format('Y-m-d'))
        ->call('goToToday')
        ->assertSet('day', $today);
});

test('a malformed day in the calendar falls back to today', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::appointments.calendar', ['current_team' => $this->team])
        ->set('day', 'not-a-date')
        ->assertSet('day', now('UTC')->format('Y-m-d'));
});
