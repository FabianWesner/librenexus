<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a booking loses the race for a slot (FR-BOOK-3): either the
 * in-transaction re-validation or the database exclusion constraint
 * (ADR-0003) rejected the requested time.
 */
class SlotNoLongerAvailableException extends RuntimeException
{
    public static function make(): self
    {
        return new self('This time is no longer available. Please pick another slot.');
    }
}
