<?php

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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Dashboard metric correctness (Epic 09, FR-DASH-1, AC-1): tenant-scoped
 * figures, the today window follows the tenant timezone, only reserving
 * statuses count, and the query count never grows with the data (NFR-PERF).
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['timezone' => 'Pacific/Auckland']);
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);

    $this->staffA = Staff::factory()->create(['team_id' => $this->team->id, 'name' => 'Aroha Apt']);
    $this->staffB = Staff::factory()->create(['team_id' => $this->team->id, 'name' => 'Bruce Busy']);
    $this->service = Service::factory()->create(['team_id' => $this->team->id, 'name' => 'Checkup']);

    AvailabilityRule::factory()->window(1, '09:00', '17:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->staffA->id,
    ]);

    app(CurrentTenant::class)->set($this->team);
});

/**
 * An appointment at a fixed UTC instant for the metric scenarios.
 */
function dashboardAppointment(Team $team, Staff $staff, Service $service, CarbonImmutable $startsAt, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
{
    return Appointment::factory()
        ->for($team, 'team')->for($staff, 'staff')->for($service, 'service')
        ->for(Customer::factory()->state(['team_id' => $team->id]), 'customer')
        ->between($startsAt->toIso8601String(), $startsAt->addHour()->toIso8601String())
        ->status($status)
        ->create();
}

test('the today count uses the tenant-timezone day boundary, not UTC', function () {
    // 09:00 UTC = 21:00 in Auckland (NZST, UTC+12).
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'));

    // Late UTC "yesterday" (2026-06-14 20:00 UTC) is 08:00 on the 15th in
    // Auckland: locally today, so it must count.
    dashboardAppointment($this->team, $this->staffA, $this->service, CarbonImmutable::parse('2026-06-14 20:00:00', 'UTC'));

    // 12:30 UTC today is 00:30 on the 16th in Auckland: locally tomorrow.
    dashboardAppointment($this->team, $this->staffA, $this->service, CarbonImmutable::parse('2026-06-15 12:30:00', 'UTC'));

    $this->actingAs($this->owner);

    $component = Livewire::test('pages::dashboard.index', ['current_team' => $this->team]);

    expect($component->instance()->todayCount)->toBe(1);
});

test('the today count and list include only reserving statuses', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'));

    $confirmed = dashboardAppointment($this->team, $this->staffA, $this->service, CarbonImmutable::parse('2026-06-14 20:00:00', 'UTC'));
    $pending = dashboardAppointment($this->team, $this->staffB, $this->service, CarbonImmutable::parse('2026-06-14 22:00:00', 'UTC'), AppointmentStatus::Pending);
    dashboardAppointment($this->team, $this->staffA, $this->service, CarbonImmutable::parse('2026-06-15 00:00:00', 'UTC'), AppointmentStatus::Cancelled);
    dashboardAppointment($this->team, $this->staffB, $this->service, CarbonImmutable::parse('2026-06-15 01:00:00', 'UTC'), AppointmentStatus::NoShow);

    $this->actingAs($this->owner);

    $component = Livewire::test('pages::dashboard.index', ['current_team' => $this->team]);

    expect($component->instance()->todayCount)->toBe(2)
        ->and($component->instance()->todayAppointments->modelKeys())->toBe([$confirmed->id, $pending->id]);
});

test('the upcoming count covers exactly the next seven days of reserving appointments', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'));

    $now = CarbonImmutable::now();

    dashboardAppointment($this->team, $this->staffA, $this->service, $now->addHours(2));
    dashboardAppointment($this->team, $this->staffB, $this->service, $now->addDays(6));
    dashboardAppointment($this->team, $this->staffA, $this->service, $now->addDays(3), AppointmentStatus::Cancelled);
    dashboardAppointment($this->team, $this->staffA, $this->service, $now->addDays(8));
    dashboardAppointment($this->team, $this->staffB, $this->service, $now->subHours(3));

    $this->actingAs($this->owner);

    $component = Livewire::test('pages::dashboard.index', ['current_team' => $this->team]);

    expect($component->instance()->upcomingCount)->toBe(2);
});

test('recent bookings are the five latest created appointments, newest first', function () {
    $now = CarbonImmutable::now();

    $appointments = collect(range(1, 7))->map(function (int $index) use ($now) {
        $appointment = dashboardAppointment($this->team, $this->staffA, $this->service, $now->addDays($index));
        $appointment->forceFill(['created_at' => $now->subDays(8 - $index)])->save();

        return $appointment;
    });

    $this->actingAs($this->owner);

    $component = Livewire::test('pages::dashboard.index', ['current_team' => $this->team]);

    expect($component->instance()->recentBookings->modelKeys())
        ->toBe($appointments->reverse()->take(5)->map->id->values()->all());
});

test('the per-staff load groups reserving appointments of the next seven days per bookable staff', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'));

    $now = CarbonImmutable::now();

    dashboardAppointment($this->team, $this->staffA, $this->service, $now->addDays(1));
    dashboardAppointment($this->team, $this->staffA, $this->service, $now->addDays(2));
    dashboardAppointment($this->team, $this->staffB, $this->service, $now->addDays(3));
    // Outside the window or not reserving: never counted.
    dashboardAppointment($this->team, $this->staffB, $this->service, $now->addDays(9));
    dashboardAppointment($this->team, $this->staffB, $this->service, $now->addDays(4), AppointmentStatus::Cancelled);

    $this->actingAs($this->owner);

    $component = Livewire::test('pages::dashboard.index', ['current_team' => $this->team]);
    $load = collect($component->instance()->staffLoad);

    expect($load)->toHaveCount(2)
        ->and($load->firstWhere('name', 'Aroha Apt')['count'])->toBe(2)
        ->and($load->firstWhere('name', 'Bruce Busy')['count'])->toBe(1);
});

test('a staff-role member sees the tenant-wide dashboard metrics (FR-DASH-1)', function () {
    $staffUser = User::factory()->create();
    $this->team->members()->attach($staffUser, ['role' => TeamRole::Staff->value]);

    dashboardAppointment($this->team, $this->staffA, $this->service, CarbonImmutable::now()->addDays(2));

    $this->actingAs($staffUser)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertSee('Upcoming (next 7 days)');
});

test('the dashboard query count does not grow with the number of appointments', function () {
    $this->queries = 0;
    DB::listen(function () {
        $this->queries++;
    });

    $now = CarbonImmutable::now();

    dashboardAppointment($this->team, $this->staffA, $this->service, $now->addDays(1));

    $this->queries = 0;

    $this->actingAs($this->owner)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertOk();

    $withOneAppointment = $this->queries;

    foreach (range(1, 9) as $index) {
        dashboardAppointment(
            $this->team,
            $index % 2 === 0 ? $this->staffA : $this->staffB,
            $this->service,
            $now->addDays($index)->addHours($index % 5),
        );
    }

    $this->queries = 0;

    $this->actingAs($this->owner)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertOk();

    expect($this->queries)->toBe($withOneAppointment);
});
