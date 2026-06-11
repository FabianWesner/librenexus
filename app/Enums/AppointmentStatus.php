<?php

namespace App\Enums;

/**
 * Appointment lifecycle (FR-APPT-4). Only time-reserving statuses block a
 * staff member's time and participate in the double-booking constraint
 * (ADR-0003); terminal statuses accept no further transitions.
 */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * Does this status hold the staff member's time (FR-APPT-4)?
     */
    public function reservesTime(): bool
    {
        return match ($this) {
            self::Pending, self::Confirmed => true,
            self::Completed, self::Cancelled, self::NoShow => false,
        };
    }

    /**
     * Terminal statuses accept no outgoing transitions; rescheduling moves
     * an active appointment instead (FR-APPT-4).
     */
    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::Confirmed], true);
    }

    /**
     * The transition matrix of FR-APPT-4; anything else is rejected.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled, self::NoShow],
            self::Confirmed => [self::Completed, self::Cancelled, self::NoShow],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    /**
     * The status values the double-booking constraint applies to.
     *
     * @return list<string>
     */
    public static function reservingValues(): array
    {
        return [self::Pending->value, self::Confirmed->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }
}
