<?php

namespace App\Actions\Availability;

use App\Data\Slot;
use App\Data\SlotComputation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The slot engine (FR-AVAIL-3/4, ARCH-STRUCTURE-3): a pure, deterministic
 * computation with no Eloquent side effects. Availability windows are
 * partitioned into service-duration slots including buffers, minus time
 * off, minus reserved appointment ranges, minus already-passed times,
 * clamped by the tenant booking policy (lead time and horizon).
 *
 * Time semantics: rules are local to the tenant timezone; results are UTC.
 * A local time skipped by a DST spring-forward shifts to the next valid
 * instant; an ambiguous fall-back time resolves to its first occurrence
 * (docs/assumptions.md).
 */
class ComputeSlots
{
    /**
     * @return Collection<int, Slot>
     */
    public function handle(SlotComputation $computation): Collection
    {
        $slots = collect();

        $leadBoundary = $computation->now->addMinutes($computation->minimumLeadTimeMinutes);
        $horizonBoundary = $computation->now->addDays($computation->bookingHorizonDays);

        foreach ($this->localDates($computation) as $date) {
            foreach ($this->windowsForDate($computation, $date) as [$windowStart, $windowEnd]) {
                $slots->push(...$this->slotsInWindow($computation, $windowStart, $windowEnd, $leadBoundary, $horizonBoundary));
            }
        }

        return $slots->values();
    }

    /**
     * Every local date in the requested range, inclusive.
     *
     * @return list<CarbonImmutable>
     */
    private function localDates(SlotComputation $computation): array
    {
        $dates = [];

        $cursor = CarbonImmutable::parse($computation->fromDate, $computation->timezone)->startOfDay();
        $last = CarbonImmutable::parse($computation->untilDate, $computation->timezone)->startOfDay();

        while ($cursor <= $last) {
            $dates[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /**
     * The merged availability windows for one local date, as UTC instants.
     * Overlapping or touching rules are unioned (docs/assumptions.md).
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windowsForDate(SlotComputation $computation, CarbonImmutable $localDate): array
    {
        $windows = [];

        foreach ($computation->weeklyRules as $rule) {
            if ($rule['weekday'] !== $localDate->dayOfWeekIso) {
                continue;
            }

            $start = $this->localTimeToUtc($localDate, $rule['start']);
            $end = $this->localTimeToUtc($localDate, $rule['end']);

            if ($end > $start) {
                $windows[] = [$start, $end];
            }
        }

        return $this->mergeWindows($windows);
    }

    /**
     * Resolve a local HH:MM on the given date to a UTC instant. "24:00"
     * normalizes to the end of the day; DST-skipped times shift forward.
     */
    private function localTimeToUtc(CarbonImmutable $localDate, string $time): CarbonImmutable
    {
        [$hour, $minute] = explode(':', $time);

        return $localDate->setTime((int) $hour, (int) $minute)->utc();
    }

    /**
     * Union overlapping or touching windows.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $windows
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function mergeWindows(array $windows): array
    {
        usort($windows, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($windows as [$start, $end]) {
            $previous = array_pop($merged);

            if ($previous !== null && $start <= $previous[1]) {
                $merged[] = [$previous[0], $previous[1]->max($end)];

                continue;
            }

            if ($previous !== null) {
                $merged[] = $previous;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }

    /**
     * Partition one window into valid slots (contiguous packing: the step is
     * buffer-before + duration + buffer-after, docs/assumptions.md).
     *
     * @return list<Slot>
     */
    private function slotsInWindow(
        SlotComputation $computation,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        CarbonImmutable $leadBoundary,
        CarbonImmutable $horizonBoundary,
    ): array {
        $slots = [];

        $stepMinutes = $computation->bufferBeforeMinutes
            + $computation->serviceDurationMinutes
            + $computation->bufferAfterMinutes;

        $start = $windowStart->addMinutes($computation->bufferBeforeMinutes);

        while (true) {
            $slot = new Slot(
                staffId: $computation->staffId,
                startsAt: $start,
                endsAt: $start->addMinutes($computation->serviceDurationMinutes),
                bufferedStartsAt: $start->subMinutes($computation->bufferBeforeMinutes),
                bufferedEndsAt: $start->addMinutes($computation->serviceDurationMinutes + $computation->bufferAfterMinutes),
            );

            if ($slot->bufferedEndsAt > $windowEnd) {
                break;
            }

            if ($this->slotIsOfferable($slot, $computation, $leadBoundary, $horizonBoundary)) {
                $slots[] = $slot;
            }

            $start = $start->addMinutes($stepMinutes);
        }

        return $slots;
    }

    private function slotIsOfferable(
        Slot $slot,
        SlotComputation $computation,
        CarbonImmutable $leadBoundary,
        CarbonImmutable $horizonBoundary,
    ): bool {
        if ($slot->startsAt < $leadBoundary || $slot->startsAt > $horizonBoundary) {
            return false;
        }

        foreach ([...$computation->timeOff, ...$computation->reserved] as [$blockedStart, $blockedEnd]) {
            if ($slot->overlaps($blockedStart, $blockedEnd)) {
                return false;
            }
        }

        return true;
    }
}
