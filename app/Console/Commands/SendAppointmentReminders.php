<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Queue reminder emails for upcoming appointments inside each team's
 * reminder window (FR-COMMS-3, Epic 08): only `confirmed` appointments
 * (plus `pending` ones when the team does not require approval), never
 * terminal or past ones. The reminder is claimed with a conditional
 * single-row UPDATE so concurrent runs cannot double-send.
 */
#[Signature('appointments:send-reminders')]
#[Description('Queue reminder emails for upcoming appointments within each team\'s reminder lead time')]
class SendAppointmentReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        foreach ($this->dueAppointments() as $appointment) {
            if (! $this->claimReminder($appointment)) {
                continue;
            }

            Mail::to($appointment->customer->email)
                ->queue(new AppointmentReminderMail($appointment));

            $queued++;
        }

        $this->info("Queued {$queued} appointment reminder(s).");

        return self::SUCCESS;
    }

    /**
     * The appointments due a reminder right now. The console has no tenant
     * context, so the tenant scope (which fails closed) is bypassed for
     * the selection and the eager loads. That is safe here: the selection
     * is keyed on the appointment rows themselves, each joined to its own
     * team for the policy window, and the mailable captures everything as
     * scalars at dispatch time, so no tenant-scoped data can leak across
     * teams (SEC-TENANT).
     *
     * @return Collection<int, Appointment>
     */
    protected function dueAppointments(): Collection
    {
        $now = CarbonImmutable::now();

        return Appointment::query()
            ->withoutGlobalScopes()
            ->select('appointments.*')
            ->join('teams', 'teams.id', '=', 'appointments.team_id')
            ->whereNull('teams.deleted_at')
            ->whereNull('appointments.reminder_sent_at')
            ->where('appointments.starts_at', '>', $now)
            ->whereRaw("appointments.starts_at <= ?::timestamptz + (teams.reminder_lead_time_hours * interval '1 hour')", [$now->toIso8601String()])
            ->where(function (Builder $query) {
                $query->where('appointments.status', AppointmentStatus::Confirmed->value)
                    ->orWhere(fn (Builder $pending) => $pending
                        ->where('appointments.status', AppointmentStatus::Pending->value)
                        ->where('teams.requires_approval', false));
            })
            ->with([
                'team',
                'service' => fn ($query) => $query->withoutGlobalScopes(),
                'staff' => fn ($query) => $query->withoutGlobalScopes(),
                'customer' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->orderBy('appointments.starts_at')
            ->get();
    }

    /**
     * Mark the appointment as reminded, but only if nothing else did so
     * first: the UPDATE is conditional on `reminder_sent_at IS NULL`, so
     * exactly one of any concurrent runs affects the row (idempotency,
     * FR-COMMS-3).
     */
    private function claimReminder(Appointment $appointment): bool
    {
        return Appointment::query()
            ->withoutGlobalScopes()
            ->whereKey($appointment->id)
            ->whereNull('reminder_sent_at')
            ->update(['reminder_sent_at' => now()]) === 1;
    }
}
