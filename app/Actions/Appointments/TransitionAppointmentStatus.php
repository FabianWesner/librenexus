<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentCancellationMail;
use App\Models\Appointment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Apply a status transition per the FR-APPT-4 matrix; anything outside it
 * is rejected server-side. Cancelling notifies the customer (FR-APPT-5,
 * FR-COMMS-2) with a queued, branded mailable.
 */
class TransitionAppointmentStatus
{
    public function handle(Appointment $appointment, AppointmentStatus $next): Appointment
    {
        if (! $appointment->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => __('An appointment cannot move from :from to :to.', [
                    'from' => $appointment->status->label(),
                    'to' => $next->label(),
                ]),
            ]);
        }

        $appointment->update(['status' => $next]);

        if ($next === AppointmentStatus::Cancelled) {
            Mail::to($appointment->customer->email)
                ->queue(new AppointmentCancellationMail($appointment));
        }

        return $appointment;
    }
}
