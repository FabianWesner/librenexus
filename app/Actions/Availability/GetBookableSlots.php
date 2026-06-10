<?php

namespace App\Actions\Availability;

use App\Data\Slot;
use App\Data\SlotComputation;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Assemble engine input from the database and return bookable slots for a
 * service (AC-5): deactivated staff, archived services, and unassigned
 * staff-service pairs yield no slots. Reserved appointment ranges join the
 * computation in Epic 06 when the Appointment model exists.
 */
class GetBookableSlots
{
    public function __construct(private ComputeSlots $computeSlots) {}

    /**
     * @return Collection<int, Slot> ordered by start time
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

        $bookableStaff = $service->staff()
            ->bookable()
            ->when($staff, fn ($query) => $query->whereKey($staff->getKey()))
            ->with(['availabilityRules', 'timeOff'])
            ->get();

        return $bookableStaff
            ->flatMap(fn (Staff $member): Collection => $this->computeSlots->handle(
                $this->computationFor($team, $service, $member, $fromDate, $untilDate, $now),
            ))
            ->sortBy(fn (Slot $slot) => $slot->startsAt)
            ->values();
    }

    private function computationFor(
        Team $team,
        Service $service,
        Staff $staff,
        string $fromDate,
        string $untilDate,
        CarbonImmutable $now,
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
            reserved: [],
            serviceDurationMinutes: $service->duration_minutes,
            bufferBeforeMinutes: $service->buffer_before_minutes,
            bufferAfterMinutes: $service->buffer_after_minutes,
            minimumLeadTimeMinutes: $team->minimum_lead_time_minutes,
            bookingHorizonDays: $team->booking_horizon_days,
        );
    }
}
