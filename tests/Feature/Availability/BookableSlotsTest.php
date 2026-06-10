<?php

use App\Actions\Availability\GetBookableSlots;
use App\Data\CurrentTenant;
use App\Data\Slot;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The orchestrator end to end (Epic 05 AC-5 + the Epic 04 deferral "slot
 * engine respects assignment"): only assigned, active staff of a bookable
 * service produce slots, and time off removes them.
 */
beforeEach(function () {
    // Booking policy defaults: 120 minutes lead time, 60 days horizon.
    // The refresh loads the database defaults onto the model instance.
    $this->team = Team::factory()->create(['timezone' => 'Europe/Berlin'])->refresh();

    app(CurrentTenant::class)->set($this->team);

    $this->service = Service::factory()->create([
        'team_id' => $this->team->id,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
    ]);

    $this->staff = Staff::factory()->create(['team_id' => $this->team->id]);
    $this->staff->services()->attach($this->service);

    // 2026-07-02 is a Thursday (ISO weekday 4); Europe/Berlin is UTC+2.
    AvailabilityRule::factory()->window(4, '09:00', '12:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->staff->id,
    ]);

    $this->now = CarbonImmutable::parse('2026-07-01 06:00:00', 'UTC');

    $this->slotsFor = fn (?Staff $staff): Collection => app(GetBookableSlots::class)->handle(
        $this->team,
        $this->service->refresh(),
        $staff,
        '2026-07-02',
        '2026-07-02',
        $this->now,
    );
});

test('an assigned active staff member yields slots in UTC', function () {
    $slots = ($this->slotsFor)($this->staff);

    expect($slots)->toHaveCount(3)
        ->and($slots->map(fn (Slot $slot): string => $slot->startsAt->toDateTimeString())->all())
        ->toBe(['2026-07-02 07:00:00', '2026-07-02 08:00:00', '2026-07-02 09:00:00'])
        ->and($slots->first()->staffId)->toBe($this->staff->id);
});

test('a staff member not assigned to the service yields no slots', function () {
    $unassigned = Staff::factory()->create(['team_id' => $this->team->id]);

    AvailabilityRule::factory()->window(4, '09:00', '12:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $unassigned->id,
    ]);

    expect(($this->slotsFor)($unassigned))->toBeEmpty();
});

test('a deactivated staff member yields no slots', function () {
    $this->staff->update(['is_active' => false]);

    expect(($this->slotsFor)($this->staff))->toBeEmpty();
});

test('an archived service yields no slots', function () {
    $this->service->update(['is_active' => false]);

    expect(($this->slotsFor)($this->staff))->toBeEmpty();
});

test('any staff merges the slots of all assigned staff sorted by start time', function () {
    $second = Staff::factory()->create(['team_id' => $this->team->id]);
    $second->services()->attach($this->service);

    // 09:30-11:30 local interleaves with the first staff member's slots.
    AvailabilityRule::factory()->window(4, '09:30', '11:30')->create([
        'team_id' => $this->team->id,
        'staff_id' => $second->id,
    ]);

    $slots = ($this->slotsFor)(null);

    expect($slots->map(fn (Slot $slot): array => [$slot->staffId, $slot->startsAt->toDateTimeString()])->all())->toBe([
        [$this->staff->id, '2026-07-02 07:00:00'],
        [$second->id, '2026-07-02 07:30:00'],
        [$this->staff->id, '2026-07-02 08:00:00'],
        [$second->id, '2026-07-02 08:30:00'],
        [$this->staff->id, '2026-07-02 09:00:00'],
    ]);
});

test('time off removes the covered slots end to end', function () {
    // Covers 09:00-10:00 local (07:00-08:00 UTC): the first slot disappears.
    TimeOff::factory()->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->staff->id,
        'starts_at' => '2026-07-02 07:00:00',
        'ends_at' => '2026-07-02 08:00:00',
    ]);

    $slots = ($this->slotsFor)($this->staff);

    expect($slots->map(fn (Slot $slot): string => $slot->startsAt->toDateTimeString())->all())
        ->toBe(['2026-07-02 08:00:00', '2026-07-02 09:00:00']);
});
