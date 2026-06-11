<?php

namespace App\Actions\SelfService;

use App\Actions\Appointments\RescheduleAppointment;
use App\Mail\AppointmentRescheduledMail;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Customer self-service reschedule via the manage link (FR-CANCEL-3,
 * Epic 08). The caller resolved the appointment by its manage token and
 * set the CurrentTenant context. Rescheduling follows the same cut-off
 * policy as cancellation (docs/assumptions.md §Booking); the atomic
 * same-row move and the terminal-status rejection live in
 * RescheduleAppointment (FR-APPT-3). On success the customer gets a
 * queued, branded notice carrying the new time and their manage link
 * (the raw token comes from the page, which has it from the URL; it is
 * never stored).
 */
class RescheduleAppointmentViaToken
{
    public function __construct(
        private EnsureWithinCancellationCutoff $ensureWithinCancellationCutoff,
        private RescheduleAppointment $rescheduleAppointment,
    ) {}

    public function handle(Appointment $appointment, CarbonImmutable $newStartsAt, string $rawManageToken): Appointment
    {
        // Terminal appointments get RescheduleAppointment's own rejection;
        // the cut-off only applies to appointments that could still move.
        if (! $appointment->status->isTerminal()) {
            $this->ensureWithinCancellationCutoff->handle($appointment);
        }

        $rescheduled = $this->rescheduleAppointment->handle($appointment, $newStartsAt);

        Mail::to($rescheduled->customer->email)
            ->queue(new AppointmentRescheduledMail($rescheduled, $rawManageToken));

        return $rescheduled;
    }
}
