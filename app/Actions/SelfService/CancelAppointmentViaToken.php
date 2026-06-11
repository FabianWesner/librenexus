<?php

namespace App\Actions\SelfService;

use App\Actions\Appointments\TransitionAppointmentStatus;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Validation\ValidationException;

/**
 * Customer self-service cancellation (FR-CANCEL-1/2, Epic 08). The caller
 * has already resolved the appointment by its manage token and set the
 * CurrentTenant context; this action only enforces the policy: terminal
 * appointments cannot change, the cancellation cut-off is respected, and
 * the actual transition (which frees the slot and queues the cancellation
 * mail, FR-CANCEL-4 + FR-COMMS-2) is delegated to the FR-APPT-4 matrix.
 */
class CancelAppointmentViaToken
{
    public function __construct(
        private EnsureWithinCancellationCutoff $ensureWithinCancellationCutoff,
        private TransitionAppointmentStatus $transitionAppointmentStatus,
    ) {}

    public function handle(Appointment $appointment): void
    {
        if ($appointment->status->isTerminal()) {
            throw ValidationException::withMessages([
                'cancel' => __('This appointment is already :status and can no longer be changed.', [
                    'status' => strtolower($appointment->status->label()),
                ]),
            ]);
        }

        $this->ensureWithinCancellationCutoff->handle($appointment);

        $this->transitionAppointmentStatus->handle($appointment, AppointmentStatus::Cancelled);
    }
}
