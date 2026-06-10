<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Pure input for the slot engine (ARCH-STRUCTURE-3): everything the
 * computation needs, with no Eloquent models involved. The wide constructor
 * is intentional: the engine input is one flat, named-argument value object;
 * grouping into nested DTOs would only obscure the engine contract.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class SlotComputation
{
    /**
     * @param  string  $timezone  Tenant timezone; all human-facing math happens here (ARCH-DATA-2).
     * @param  CarbonImmutable  $now  Current instant (UTC).
     * @param  string  $fromDate  First local date (Y-m-d) to compute, inclusive.
     * @param  string  $untilDate  Last local date (Y-m-d) to compute, inclusive.
     * @param  list<array{weekday: int, start: string, end: string}>  $weeklyRules  ISO weekday (1 = Monday) + HH:MM times in the tenant timezone; "24:00" = end of day.
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $timeOff  UTC intervals during which no slot may be offered.
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $reserved  Buffered UTC ranges of time-reserving appointments (FR-APPT-4).
     * @param  int  $minimumLeadTimeMinutes  From the tenant booking policy (FR-TENANT-8).
     * @param  int  $bookingHorizonDays  From the tenant booking policy (FR-TENANT-8).
     */
    public function __construct(
        public string $timezone,
        public CarbonImmutable $now,
        public string $fromDate,
        public string $untilDate,
        public int $staffId,
        public array $weeklyRules,
        public array $timeOff,
        public array $reserved,
        public int $serviceDurationMinutes,
        public int $bufferBeforeMinutes,
        public int $bufferAfterMinutes,
        public int $minimumLeadTimeMinutes,
        public int $bookingHorizonDays,
    ) {}
}
