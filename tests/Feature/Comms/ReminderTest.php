<?php

use App\Console\Commands\SendAppointmentReminders;
use App\Enums\AppointmentStatus;
use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Scheduled reminders (FR-COMMS-3, Epic 08, AC-5): exactly the upcoming
 * `confirmed` appointments (plus `pending` when the team does not require
 * approval) inside each team's reminder window are reminded, idempotently,
 * with a queued branded mailable.
 */
covers(SendAppointmentReminders::class, AppointmentReminderMail::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-08T08:00:00Z'));
});

/**
 * An appointment for a fresh team with the given booking-policy fields,
 * starting the given number of hours from "now".
 *
 * @param  array<string, mixed>  $teamAttributes
 */
function reminderAppointment(float $hoursFromNow, AppointmentStatus $status = AppointmentStatus::Confirmed, array $teamAttributes = []): Appointment
{
    $team = Team::factory()->create(['timezone' => 'UTC', 'reminder_lead_time_hours' => 24, ...$teamAttributes]);
    $staff = Staff::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create(['team_id' => $team->id, 'duration_minutes' => 60]);
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    $startsAt = CarbonImmutable::now()->addMinutes((int) round($hoursFromNow * 60));

    return Appointment::factory()
        ->for($team, 'team')->for($staff, 'staff')->for($service, 'service')->for($customer, 'customer')
        ->between($startsAt->toIso8601String(), $startsAt->addMinutes(60)->toIso8601String())
        ->status($status)
        ->create();
}

test('reminders go exactly to the FR-COMMS-3 set', function () {
    $confirmed = reminderAppointment(10);
    $pendingAutoConfirm = reminderAppointment(10, AppointmentStatus::Pending, ['requires_approval' => false]);
    $pendingNeedsApproval = reminderAppointment(10, AppointmentStatus::Pending, ['requires_approval' => true]);
    $cancelled = reminderAppointment(10, AppointmentStatus::Cancelled);
    $noShow = reminderAppointment(10, AppointmentStatus::NoShow);
    $completed = reminderAppointment(10, AppointmentStatus::Completed);
    $outsideWindow = reminderAppointment(30);
    $past = reminderAppointment(-2);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    $remindedEmails = [
        $confirmed->customer()->withoutGlobalScopes()->sole()->email,
        $pendingAutoConfirm->customer()->withoutGlobalScopes()->sole()->email,
    ];

    foreach ($remindedEmails as $email) {
        Mail::assertQueued(AppointmentReminderMail::class, fn (AppointmentReminderMail $mail): bool => $mail->hasTo($email));
    }

    Mail::assertQueuedCount(2);

    expect($confirmed->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($pendingAutoConfirm->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($pendingNeedsApproval->fresh()->reminder_sent_at)->toBeNull()
        ->and($cancelled->fresh()->reminder_sent_at)->toBeNull()
        ->and($noShow->fresh()->reminder_sent_at)->toBeNull()
        ->and($completed->fresh()->reminder_sent_at)->toBeNull()
        ->and($outsideWindow->fresh()->reminder_sent_at)->toBeNull()
        ->and($past->fresh()->reminder_sent_at)->toBeNull();
});

test('the window respects each team\'s own reminder lead time', function () {
    $longLead = reminderAppointment(10, AppointmentStatus::Confirmed, ['reminder_lead_time_hours' => 24]);
    $shortLead = reminderAppointment(10, AppointmentStatus::Confirmed, ['reminder_lead_time_hours' => 2]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueuedCount(1);

    expect($longLead->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($shortLead->fresh()->reminder_sent_at)->toBeNull();
});

test('an appointment starting exactly at the window edge is included', function () {
    $atEdge = reminderAppointment(24);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    expect($atEdge->fresh()->reminder_sent_at)->not->toBeNull();
    Mail::assertQueuedCount(1);
});

test('running the command twice queues each reminder exactly once and reports the count', function () {
    reminderAppointment(10);

    $this->artisan('appointments:send-reminders')
        ->expectsOutputToContain('Queued 1 appointment reminder(s).')
        ->assertSuccessful();
    $this->artisan('appointments:send-reminders')
        ->expectsOutputToContain('Queued 0 appointment reminder(s).')
        ->assertSuccessful();

    Mail::assertQueuedCount(1);
});

test('the command eager loads everything the mailable needs', function () {
    // Two due appointments: Eloquent only arms the lazy-loading guard on
    // result sets with more than one model.
    reminderAppointment(10);
    reminderAppointment(10);

    // Forbidding lazy loads proves the team/service/staff/customer eager
    // loads stay in place (the tenant-less console context depends on the
    // scope-free eager loads; a lazy team load would also be an N+1).
    Model::preventLazyLoading();

    try {
        $this->artisan('appointments:send-reminders')->assertSuccessful();
    } finally {
        Model::preventLazyLoading(false);
    }

    Mail::assertQueuedCount(2);
});

test('a claimed row is never selected again', function () {
    $appointment = reminderAppointment(10);
    $appointment->update(['reminder_sent_at' => now()]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('a row claimed mid-run by a concurrent worker is skipped, not double-sent', function () {
    $claimed = reminderAppointment(10);
    $unclaimed = reminderAppointment(12);

    // Simulate a concurrent worker winning the claim between selection and
    // this run's conditional update: the first row is in the selected set,
    // but the claim must fail and only that row is skipped; processing
    // continues with the rest of the selection.
    Appointment::query()->withoutGlobalScopes()->whereKey($claimed->id)
        ->update(['reminder_sent_at' => now()->subSecond()]);

    $command = new class extends SendAppointmentReminders
    {
        protected function dueAppointments(): Collection
        {
            // Return the stale selection that still contains the claimed row.
            return Appointment::query()->withoutGlobalScopes()
                ->with([
                    'team',
                    'service' => fn ($query) => $query->withoutGlobalScopes(),
                    'staff' => fn ($query) => $query->withoutGlobalScopes(),
                    'customer' => fn ($query) => $query->withoutGlobalScopes(),
                ])
                ->orderBy('starts_at')
                ->get();
        }
    };

    $command->setLaravel(app());
    $command->setOutput(new OutputStyle(
        new ArrayInput([]),
        new BufferedOutput,
    ));

    expect($command->handle())->toBe(0);

    $unclaimedEmail = Customer::query()->withoutGlobalScopes()
        ->findOrFail($unclaimed->customer_id)->email;

    Mail::assertQueuedCount(1);
    Mail::assertQueued(AppointmentReminderMail::class, function (AppointmentReminderMail $mail) use ($unclaimedEmail): bool {
        return $mail->hasTo($unclaimedEmail);
    });
});

test('the reminder mail is branded and renders the appointment details', function () {
    $appointment = reminderAppointment(10, AppointmentStatus::Confirmed, [
        'name' => 'Reminder Clinic',
        'timezone' => 'Europe/Berlin',
        'contact_email' => 'desk@reminder.example',
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    Mail::assertQueued(AppointmentReminderMail::class, function (AppointmentReminderMail $mail) use ($appointment): bool {
        $rendered = $mail->render();
        $localStartsAt = $appointment->starts_at->setTimezone('Europe/Berlin')->isoFormat('dddd, MMMM D, YYYY [at] HH:mm');

        expect($rendered)->toContain('Reminder Clinic')
            ->toContain($localStartsAt)
            ->toContain('Europe/Berlin')
            ->toContain('manage link from your confirmation email')
            ->and($mail->envelope()->subject)->toBe('Reminder: your appointment at Reminder Clinic')
            ->and($mail->envelope()->replyTo[0]->address)->toBe('desk@reminder.example');

        return true;
    });
});
