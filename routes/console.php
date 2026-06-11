<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// FR-COMMS-3: queue reminder mails inside each team's reminder window. The
// command claims each row idempotently, so the cadence only bounds latency.
Schedule::command('appointments:send-reminders')->everyFifteenMinutes();
