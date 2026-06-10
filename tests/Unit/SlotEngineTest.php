<?php

use App\Actions\Availability\ComputeSlots;
use App\Data\Slot;
use App\Data\SlotComputation;
use Carbon\CarbonImmutable;

/**
 * Slot-engine unit suite (FR-AVAIL-3/4, Epic 05 AC-2..AC-4). The engine is
 * critical domain logic with elevated coverage and mutation targets
 * (test-plan.md), hence covers().
 *
 * Known equivalent mutants (accepted, QG-MUTATION remediation note),
 * verified case by case: the zero-width-window comparison (end > start vs
 * >=) is invisible because an empty window can never fit a slot, and
 * removing either (int) cast on the exploded HH:MM parts is invisible
 * because PHP coerces numeric strings in setTime(). Every other engine
 * mutant is killed by a test in this suite.
 */
covers(ComputeSlots::class, Slot::class, SlotComputation::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function computation(array $overrides = []): SlotComputation
{
    $defaults = [
        'timezone' => 'Europe/Berlin',
        'now' => CarbonImmutable::parse('2026-06-01T00:00:00', 'UTC'),
        'fromDate' => '2026-06-08',
        'untilDate' => '2026-06-08',
        'staffId' => 7,
        // 2026-06-08 is a Monday.
        'weeklyRules' => [['weekday' => 1, 'start' => '09:00', 'end' => '12:00']],
        'timeOff' => [],
        'reserved' => [],
        'serviceDurationMinutes' => 60,
        'bufferBeforeMinutes' => 0,
        'bufferAfterMinutes' => 0,
        'minimumLeadTimeMinutes' => 120,
        'bookingHorizonDays' => 60,
    ];

    return new SlotComputation(...[...$defaults, ...$overrides]);
}

/**
 * @return list<string>
 */
function localStarts(SlotComputation $computation): array
{
    return (new ComputeSlots)->handle($computation)
        ->map(fn (Slot $slot): string => $slot->startsAt->setTimezone($computation->timezone)->format('Y-m-d H:i'))
        ->all();
}

test('a window is partitioned into service-duration slots', function () {
    expect(localStarts(computation()))->toBe([
        '2026-06-08 09:00',
        '2026-06-08 10:00',
        '2026-06-08 11:00',
    ]);
});

test('slots carry the staff id and utc instants', function () {
    $slot = (new ComputeSlots)->handle(computation())->first();

    // 09:00 Europe/Berlin in June is 07:00 UTC (CEST, UTC+2).
    expect($slot->staffId)->toBe(7)
        ->and($slot->startsAt->toIso8601String())->toBe('2026-06-08T07:00:00+00:00')
        ->and($slot->endsAt->toIso8601String())->toBe('2026-06-08T08:00:00+00:00')
        ->and($slot->startsAt->timezoneName)->toBe('UTC');
});

test('buffers shape both the customer window and the packing step', function () {
    $slots = (new ComputeSlots)->handle(computation([
        'serviceDurationMinutes' => 45,
        'bufferBeforeMinutes' => 5,
        'bufferAfterMinutes' => 10,
    ]));

    // Step = 5 + 45 + 10 = 60; first customer start = window start + 5.
    expect($slots->map(fn (Slot $slot) => $slot->startsAt->setTimezone('Europe/Berlin')->format('H:i'))->all())
        ->toBe(['09:05', '10:05', '11:05']);

    $first = $slots->first();

    expect($first->bufferedStartsAt->setTimezone('Europe/Berlin')->format('H:i'))->toBe('09:00')
        ->and($first->bufferedEndsAt->setTimezone('Europe/Berlin')->format('H:i'))->toBe('10:00')
        ->and($first->endsAt->setTimezone('Europe/Berlin')->format('H:i'))->toBe('09:50');
});

test('back-to-back slots never overlap including buffers', function () {
    $slots = (new ComputeSlots)->handle(computation([
        'serviceDurationMinutes' => 50,
        'bufferBeforeMinutes' => 10,
        'bufferAfterMinutes' => 15,
    ]));

    $previous = null;

    expect($slots)->not->toBeEmpty();

    foreach ($slots as $slot) {
        if ($previous !== null) {
            expect($slot->bufferedStartsAt->greaterThanOrEqualTo($previous->bufferedEndsAt))->toBeTrue();
        }

        $previous = $slot;
    }
});

test('a service longer than every window yields zero slots', function () {
    $slots = (new ComputeSlots)->handle(computation([
        'serviceDurationMinutes' => 240,
    ]));

    expect($slots)->toBeEmpty();
});

test('a slot must fit entirely inside the window including buffers', function () {
    // 09:00-12:00 window, 100-minute service + 30 buffer = 130-minute step:
    // 09:00 slot fits (ends 11:10), the next start 11:10 would end 13:20.
    $slots = (new ComputeSlots)->handle(computation([
        'serviceDurationMinutes' => 100,
        'bufferAfterMinutes' => 30,
    ]));

    expect($slots)->toHaveCount(1);
});

test('time off fully covering a window removes all its slots', function () {
    $slots = (new ComputeSlots)->handle(computation([
        'timeOff' => [[
            CarbonImmutable::parse('2026-06-08T06:00:00', 'UTC'),
            CarbonImmutable::parse('2026-06-08T11:00:00', 'UTC'),
        ]],
    ]));

    expect($slots)->toBeEmpty();
});

test('time off partially covering a window removes only the overlapped slots', function () {
    // Blocks 09:00-10:30 local (07:00-08:30 UTC): kills 09:00 and 10:00 slots.
    $slots = localStarts(computation([
        'timeOff' => [[
            CarbonImmutable::parse('2026-06-08T07:00:00', 'UTC'),
            CarbonImmutable::parse('2026-06-08T08:30:00', 'UTC'),
        ]],
    ]));

    expect($slots)->toBe(['2026-06-08 11:00']);
});

test('a touching interval does not block the adjacent slot', function () {
    // Time off ending exactly at 09:00 local leaves the 09:00 slot offerable.
    $slots = localStarts(computation([
        'timeOff' => [[
            CarbonImmutable::parse('2026-06-08T05:00:00', 'UTC'),
            CarbonImmutable::parse('2026-06-08T07:00:00', 'UTC'),
        ]],
    ]));

    expect($slots)->toContain('2026-06-08 09:00');
});

test('reserved appointment ranges block overlapping slots', function () {
    $slots = localStarts(computation([
        'reserved' => [[
            CarbonImmutable::parse('2026-06-08T08:00:00', 'UTC'), // 10:00 local
            CarbonImmutable::parse('2026-06-08T09:00:00', 'UTC'), // 11:00 local
        ]],
    ]));

    expect($slots)->toBe(['2026-06-08 09:00', '2026-06-08 11:00']);
});

test('slots before the minimum lead time are excluded', function () {
    // Now = 08:30 local on the requested day; 120 min lead kills 09:00 and 10:00.
    $slots = localStarts(computation([
        'now' => CarbonImmutable::parse('2026-06-08T06:30:00', 'UTC'),
    ]));

    expect($slots)->toBe(['2026-06-08 11:00']);
});

test('a slot starting exactly at the lead boundary is offered', function () {
    // Now = 07:00 local; lead 120 => boundary 09:00 local, inclusive.
    $slots = localStarts(computation([
        'now' => CarbonImmutable::parse('2026-06-08T05:00:00', 'UTC'),
    ]));

    expect($slots)->toContain('2026-06-08 09:00');
});

test('slots beyond the booking horizon are excluded', function () {
    $slots = (new ComputeSlots)->handle(computation([
        'now' => CarbonImmutable::parse('2026-06-01T00:00:00', 'UTC'),
        'bookingHorizonDays' => 5,
        'fromDate' => '2026-06-08',
        'untilDate' => '2026-06-08',
    ]));

    expect($slots)->toBeEmpty();
});

test('already-passed days yield no slots', function () {
    $slots = (new ComputeSlots)->handle(computation([
        'now' => CarbonImmutable::parse('2026-07-01T00:00:00', 'UTC'),
    ]));

    expect($slots)->toBeEmpty();
});

test('rules apply only to their weekday', function () {
    $slots = (new ComputeSlots)->handle(computation([
        'fromDate' => '2026-06-08',
        'untilDate' => '2026-06-14',
        'weeklyRules' => [['weekday' => 3, 'start' => '09:00', 'end' => '10:00']],
    ]));

    expect($slots)->toHaveCount(1)
        ->and($slots->first()->startsAt->setTimezone('Europe/Berlin')->format('Y-m-d'))
        ->toBe('2026-06-10');
});

test('overlapping rules are unioned into one window', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [
            ['weekday' => 1, 'start' => '09:00', 'end' => '11:00'],
            ['weekday' => 1, 'start' => '10:00', 'end' => '13:00'],
        ],
    ]));

    expect($slots)->toBe([
        '2026-06-08 09:00',
        '2026-06-08 10:00',
        '2026-06-08 11:00',
        '2026-06-08 12:00',
    ]);
});

test('touching rules are unioned without losing the boundary slot', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [
            ['weekday' => 1, 'start' => '09:00', 'end' => '10:30'],
            ['weekday' => 1, 'start' => '10:30', 'end' => '12:00'],
        ],
    ]));

    expect($slots)->toBe([
        '2026-06-08 09:00',
        '2026-06-08 10:00',
        '2026-06-08 11:00',
    ]);
});

test('a rule for another weekday before the matching rule is skipped, not fatal', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [
            ['weekday' => 3, 'start' => '08:00', 'end' => '20:00'],
            ['weekday' => 1, 'start' => '09:00', 'end' => '12:00'],
        ],
    ]));

    expect($slots)->toBe([
        '2026-06-08 09:00',
        '2026-06-08 10:00',
        '2026-06-08 11:00',
    ]);
});

test('out-of-order overlapping rules are still unioned by start time', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [
            ['weekday' => 1, 'start' => '10:00', 'end' => '13:00'],
            ['weekday' => 1, 'start' => '09:00', 'end' => '11:00'],
        ],
    ]));

    expect($slots)->toBe([
        '2026-06-08 09:00',
        '2026-06-08 10:00',
        '2026-06-08 11:00',
        '2026-06-08 12:00',
    ]);
});

test('a rule nested inside another never shrinks the merged window', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [
            ['weekday' => 1, 'start' => '10:00', 'end' => '11:00'],
            ['weekday' => 1, 'start' => '09:00', 'end' => '13:00'],
        ],
    ]));

    expect($slots)->toBe([
        '2026-06-08 09:00',
        '2026-06-08 10:00',
        '2026-06-08 11:00',
        '2026-06-08 12:00',
    ]);
});

test('a later disjoint rule survives an earlier merge', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [
            ['weekday' => 1, 'start' => '09:00', 'end' => '10:00'],
            ['weekday' => 1, 'start' => '09:30', 'end' => '11:00'],
            ['weekday' => 1, 'start' => '14:00', 'end' => '15:00'],
        ],
    ]));

    expect($slots)->toBe([
        '2026-06-08 09:00',
        '2026-06-08 10:00',
        '2026-06-08 14:00',
    ]);
});

test('a slot starting exactly at the horizon boundary is offered', function () {
    // now 07:00 UTC + 7 days lands exactly on the Monday 09:00 local slot
    // (07:00 UTC in June); the horizon is inclusive.
    $slots = localStarts(computation([
        'now' => CarbonImmutable::parse('2026-06-01T07:00:00', 'UTC'),
        'bookingHorizonDays' => 7,
    ]));

    expect($slots)->toContain('2026-06-08 09:00')
        ->and($slots)->not->toContain('2026-06-08 10:00');
});

test('a rule overlapping the second of two disjoint windows merges with the right one', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [
            ['weekday' => 1, 'start' => '09:00', 'end' => '10:00'],
            ['weekday' => 1, 'start' => '12:00', 'end' => '13:00'],
            ['weekday' => 1, 'start' => '12:30', 'end' => '14:00'],
        ],
    ]));

    expect($slots)->toBe([
        '2026-06-08 09:00',
        '2026-06-08 12:00',
        '2026-06-08 13:00',
    ]);
});

test('disjoint rules on the same day stay separate windows', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [
            ['weekday' => 1, 'start' => '09:00', 'end' => '10:00'],
            ['weekday' => 1, 'start' => '14:00', 'end' => '15:00'],
        ],
    ]));

    expect($slots)->toBe(['2026-06-08 09:00', '2026-06-08 14:00']);
});

test('half-hour rule offsets are honored exactly', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [['weekday' => 1, 'start' => '09:30', 'end' => '11:45']],
    ]));

    expect($slots)->toBe(['2026-06-08 09:30', '2026-06-08 10:30']);
});

test('an inverted rule produces no window', function () {
    $slots = (new ComputeSlots)->handle(computation([
        'weeklyRules' => [['weekday' => 1, 'start' => '12:00', 'end' => '09:00']],
    ]));

    expect($slots)->toBeEmpty();
});

test('a window ending at midnight is fully usable', function () {
    $slots = localStarts(computation([
        'weeklyRules' => [['weekday' => 1, 'start' => '22:00', 'end' => '24:00']],
    ]));

    expect($slots)->toBe(['2026-06-08 22:00', '2026-06-08 23:00']);
});

test('dst spring forward shortens the window instead of inventing times', function () {
    // Europe/Berlin skips 02:00-03:00 on 2026-03-29 (a Sunday). A 01:00-05:00
    // rule yields a real window of 01:00, then 03:00-05:00: the 02:00 local
    // start does not exist and must not be offered.
    $slots = (new ComputeSlots)->handle(computation([
        'now' => CarbonImmutable::parse('2026-03-01T00:00:00', 'UTC'),
        'fromDate' => '2026-03-29',
        'untilDate' => '2026-03-29',
        'weeklyRules' => [['weekday' => 7, 'start' => '01:00', 'end' => '05:00']],
    ]));

    $utcStarts = $slots->map(fn (Slot $slot) => $slot->startsAt->toIso8601String())->all();

    // 01:00 CET = 00:00 UTC; the window continues at 03:00 CEST = 01:00 UTC.
    expect($utcStarts)->toBe([
        '2026-03-29T00:00:00+00:00',
        '2026-03-29T01:00:00+00:00',
        '2026-03-29T02:00:00+00:00',
    ])
        ->and($slots->map(fn (Slot $slot) => $slot->startsAt->setTimezone('Europe/Berlin')->format('H:i'))->all())
        ->toBe(['01:00', '03:00', '04:00']);
});

test('dst fall back resolves ambiguous local times to their first occurrence', function () {
    // Europe/Berlin repeats 02:00-03:00 on 2026-10-25 (a Sunday). The 02:00
    // window start resolves to the first occurrence (CEST, UTC+2), and the
    // 02:00-04:00 rule covers three real hours.
    $slots = (new ComputeSlots)->handle(computation([
        'now' => CarbonImmutable::parse('2026-10-01T00:00:00', 'UTC'),
        'fromDate' => '2026-10-25',
        'untilDate' => '2026-10-25',
        'weeklyRules' => [['weekday' => 7, 'start' => '02:00', 'end' => '04:00']],
    ]));

    $utcStarts = $slots->map(fn (Slot $slot) => $slot->startsAt->toIso8601String())->all();

    // First 02:00 CEST = 00:00 UTC; 04:00 CET = 03:00 UTC, so three slots.
    expect($utcStarts)->toBe([
        '2026-10-25T00:00:00+00:00',
        '2026-10-25T01:00:00+00:00',
        '2026-10-25T02:00:00+00:00',
    ]);
});

test('the computation is deterministic regardless of the server timezone', function () {
    $reference = localStarts(computation());

    $original = date_default_timezone_get();

    try {
        foreach (['America/New_York', 'Pacific/Auckland', 'UTC'] as $serverTimezone) {
            date_default_timezone_set($serverTimezone);

            expect(localStarts(computation()))->toBe($reference);
        }
    } finally {
        date_default_timezone_set($original);
    }
});

test('a multi-day range produces slots for every matching day', function () {
    $slots = (new ComputeSlots)->handle(computation([
        'fromDate' => '2026-06-08',
        'untilDate' => '2026-06-21',
    ]));

    // Two Mondays in range, three slots each.
    expect($slots)->toHaveCount(6);
});

test('slot overlap detection treats touching ranges as free', function () {
    $slot = new Slot(
        staffId: 1,
        startsAt: CarbonImmutable::parse('2026-06-08T09:00:00', 'UTC'),
        endsAt: CarbonImmutable::parse('2026-06-08T10:00:00', 'UTC'),
        bufferedStartsAt: CarbonImmutable::parse('2026-06-08T09:00:00', 'UTC'),
        bufferedEndsAt: CarbonImmutable::parse('2026-06-08T10:00:00', 'UTC'),
    );

    expect($slot->overlaps(
        CarbonImmutable::parse('2026-06-08T10:00:00', 'UTC'),
        CarbonImmutable::parse('2026-06-08T11:00:00', 'UTC'),
    ))->toBeFalse()
        ->and($slot->overlaps(
            CarbonImmutable::parse('2026-06-08T08:00:00', 'UTC'),
            CarbonImmutable::parse('2026-06-08T09:00:00', 'UTC'),
        ))->toBeFalse()
        ->and($slot->overlaps(
            CarbonImmutable::parse('2026-06-08T09:59:00', 'UTC'),
            CarbonImmutable::parse('2026-06-08T10:01:00', 'UTC'),
        ))->toBeTrue();
});
