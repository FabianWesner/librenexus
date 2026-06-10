<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A concrete bookable start time (requirements.md glossary). All instants
 * are UTC; the customer-facing window is [startsAt, endsAt] and the time
 * the staff member is actually blocked, including service buffers, is
 * [bufferedStartsAt, bufferedEndsAt] (FR-AVAIL-3, ADR-0003).
 */
final readonly class Slot
{
    public function __construct(
        public int $staffId,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public CarbonImmutable $bufferedStartsAt,
        public CarbonImmutable $bufferedEndsAt,
    ) {}

    /**
     * Does this slot's buffered range overlap the given UTC interval?
     */
    public function overlaps(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->bufferedStartsAt < $end && $start < $this->bufferedEndsAt;
    }
}
