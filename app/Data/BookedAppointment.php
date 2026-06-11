<?php

namespace App\Data;

use App\Models\Appointment;

/**
 * Result of a successful booking: the appointment plus the raw manage
 * token, which exists only in memory and in the confirmation email
 * (SEC-TOKEN-1/2).
 */
final readonly class BookedAppointment
{
    public function __construct(
        public Appointment $appointment,
        public string $rawManageToken,
    ) {}
}
