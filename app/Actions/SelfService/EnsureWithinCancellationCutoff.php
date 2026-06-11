<?php

namespace App\Actions\SelfService;

use App\Models\Appointment;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Enforce the tenant's cancellation cut-off (FR-CANCEL-2, FR-TENANT-8) for
 * customer self-service changes: a change is allowed only strictly before
 * `starts_at` minus the cut-off window; exactly at the boundary it is
 * already refused (test-plan §cancellation exactly at the cut-off
 * boundary). Rescheduling follows the same policy (docs/assumptions.md
 * §Booking).
 */
class EnsureWithinCancellationCutoff
{
    public function handle(Appointment $appointment): void
    {
        if (! $this->hasClosed($appointment)) {
            return;
        }

        throw ValidationException::withMessages([
            'cutoff' => $this->closedMessage($appointment),
        ]);
    }

    /**
     * Has the change window closed? Closed means now() is at or past
     * `starts_at` minus the cut-off (the boundary itself counts as closed).
     */
    public function hasClosed(Appointment $appointment): bool
    {
        $deadline = $appointment->starts_at
            ->copy()
            ->subMinutes($appointment->team->cancellation_cutoff_minutes);

        return Carbon::now()->greaterThanOrEqualTo($deadline);
    }

    /**
     * The customer-facing refusal, naming the cut-off window.
     */
    public function closedMessage(Appointment $appointment): string
    {
        return __('Online changes are closed: this appointment can only be cancelled or rescheduled up to :window before it starts. Please contact :team directly.', [
            'window' => $this->windowLabel($appointment),
            'team' => $appointment->team->name,
        ]);
    }

    private function windowLabel(Appointment $appointment): string
    {
        return CarbonInterval::minutes($appointment->team->cancellation_cutoff_minutes)
            ->cascade()
            ->forHumans();
    }
}
