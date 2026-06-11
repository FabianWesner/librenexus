<?php

namespace App\Actions\Availability;

use App\Data\Slot;
use App\Data\SlotComputation;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Assemble engine input from the database and return bookable slots for a
 * service (AC-5): deactivated staff, archived services, and unassigned
 * staff-service pairs yield no slots. Time-reserving appointments
 * (FR-APPT-4) block their buffered ranges. Results are ordered by start
 * time, ties broken by ascending staff id, so "any available" picks are
 * deterministic (FR-BOOK-2, AC-7).
 */
class GetBookableSlots
{
    public function __construct(private ComputeSlots $computeSlots) {}

    /**
     * @return Collection<int, Slot> ordered by start time, then staff id
     */
    public function handle(
        Team $team,
        Service $service,
        ?Staff $staff,
        string $fromDate,
        string $untilDate,
        ?CarbonImmutable $now = null,
    ): Collection {
        if (! $service->is_active) {
            return collect();
        }

        $now ??= CarbonImmutable::now();

        [$rangeStart, $rangeEnd] = $this->utcRange($team, $fromDate, $untilDate);

        $bookableStaff = $service->staff()
            ->bookable()
            ->when($staff, fn ($query) => $query->whereKey($staff->getKey()))
            ->with('availabilityRules')
            ->with('timeOff', fn (Relation $query) => $query
                ->where('starts_at', '<', $rangeEnd)
                ->where('ends_at', '>', $rangeStart))
            ->get();

        $reservedByStaff = $this->reservedRangesByStaff($bookableStaff, $rangeStart, $rangeEnd);

        return $bookableStaff
            ->flatMap(fn (Staff $member): Collection => $this->computeSlots->handle(
                $this->computationFor($team, $service, $member, $fromDate, $untilDate, $now, $reservedByStaff->get($member->id, [])),
            ))
            ->sortBy(fn (Slot $slot): array => [$slot->startsAt->getTimestamp(), $slot->staffId])
            ->values();
    }

    /**
     * The requested local date range as a UTC interval (used to bound the
     * time-off and appointment queries).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function utcRange(Team $team, string $fromDate, string $untilDate): array
    {
        return [
            CarbonImmutable::parse($fromDate, $team->timezone)->startOfDay()->utc(),
            CarbonImmutable::parse($untilDate, $team->timezone)->startOfDay()->addDay()->utc(),
        ];
    }

    /**
     * Buffered UTC ranges of time-reserving appointments per staff member
     * (FR-APPT-4): exactly the rows the exclusion constraint guards.
     *
     * @param  EloquentCollection<int, Staff>  $bookableStaff
     * @return Collection<int, list<array{0: CarbonImmutable, 1: CarbonImmutable}>>
     */
    private function reservedRangesByStaff(
        EloquentCollection $bookableStaff,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): Collection {
        if ($bookableStaff->isEmpty()) {
            return collect();
        }

        return Appointment::query()
            ->reservingTime()
            ->whereIn('staff_id', $bookableStaff->modelKeys())
            ->where('buffered_starts_at', '<', $rangeEnd)
            ->where('buffered_ends_at', '>', $rangeStart)
            ->get(['staff_id', 'buffered_starts_at', 'buffered_ends_at'])
            ->groupBy('staff_id')
            ->map(fn (Collection $appointments): array => array_values($appointments
                ->map(fn (Appointment $appointment): array => [
                    CarbonImmutable::parse($appointment->buffered_starts_at),
                    CarbonImmutable::parse($appointment->buffered_ends_at),
                ])
                ->all()));
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $reserved
     */
    private function computationFor(
        Team $team,
        Service $service,
        Staff $staff,
        string $fromDate,
        string $untilDate,
        CarbonImmutable $now,
        array $reserved,
    ): SlotComputation {
        $weeklyRules = array_values($staff->availabilityRules
            ->map(fn (AvailabilityRule $rule): array => [
                'weekday' => $rule->weekday,
                'start' => substr($rule->start_time, 0, 5),
                'end' => substr($rule->end_time, 0, 5),
            ])
            ->all());

        $timeOff = array_values($staff->timeOff
            ->map(fn (TimeOff $interval): array => [
                CarbonImmutable::parse($interval->starts_at),
                CarbonImmutable::parse($interval->ends_at),
            ])
            ->all());

        return new SlotComputation(
            timezone: $team->timezone,
            now: $now,
            fromDate: $fromDate,
            untilDate: $untilDate,
            staffId: (int) $staff->id,
            weeklyRules: $weeklyRules,
            timeOff: $timeOff,
            reserved: $reserved,
            serviceDurationMinutes: $service->duration_minutes,
            bufferBeforeMinutes: $service->buffer_before_minutes,
            bufferAfterMinutes: $service->buffer_after_minutes,
            minimumLeadTimeMinutes: $team->minimum_lead_time_minutes,
            bookingHorizonDays: $team->booking_horizon_days,
        );
    }
}
