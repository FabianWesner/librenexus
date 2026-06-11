<?php

namespace App\Actions\Appointments;

use App\Actions\Availability\GetBookableSlots;
use App\Data\Slot;
use App\Exceptions\SlotNoLongerAvailableException;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move an active appointment to another slot atomically (FR-APPT-3, AC-5).
 * The same row is updated inside one transaction, so the exclusion
 * constraint (ADR-0003) checks the move as a unit: the appointment's own
 * range never blocks it and an overlap with anyone else loses cleanly. The
 * manage token stays valid because the appointment identity is unchanged.
 */
class RescheduleAppointment
{
    private const string EXCLUSION_VIOLATION = '23P01';

    public function __construct(private GetBookableSlots $getBookableSlots) {}

    public function handle(Appointment $appointment, CarbonImmutable $newStartsAt): Appointment
    {
        if ($appointment->status->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => __('A :status appointment cannot be rescheduled.', [
                    'status' => $appointment->status->label(),
                ]),
            ]);
        }

        try {
            return DB::transaction(function () use ($appointment, $newStartsAt): Appointment {
                $slot = $this->matchingSlot($appointment, $newStartsAt);

                if ($slot === null) {
                    throw SlotNoLongerAvailableException::make();
                }

                $appointment->update([
                    'staff_id' => $slot->staffId,
                    'starts_at' => $slot->startsAt,
                    'ends_at' => $slot->endsAt,
                    'buffered_starts_at' => $slot->bufferedStartsAt,
                    'buffered_ends_at' => $slot->bufferedEndsAt,
                ]);

                return $appointment;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::EXCLUSION_VIOLATION) {
                throw SlotNoLongerAvailableException::make();
            }

            throw $exception;
        }
    }

    private function matchingSlot(Appointment $appointment, CarbonImmutable $newStartsAt): ?Slot
    {
        $team = $appointment->team;
        $localDate = $newStartsAt->setTimezone($team->timezone)->format('Y-m-d');

        return $this->getBookableSlots
            ->handle(
                team: $team,
                service: $appointment->service,
                staff: $appointment->staff,
                fromDate: $localDate,
                untilDate: $localDate,
                excludeAppointmentId: $appointment->id,
            )
            ->first(fn (Slot $slot): bool => $slot->startsAt->equalTo($newStartsAt));
    }
}
