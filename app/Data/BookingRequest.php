<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Validated input for booking an appointment (FR-BOOK-2/5). The start
 * instant is UTC; customer fields are already validated by the caller.
 */
final readonly class BookingRequest
{
    public function __construct(
        public int $serviceId,
        public ?int $staffId,
        public CarbonImmutable $startsAt,
        public string $customerName,
        public string $customerEmail,
        public ?string $customerPhone,
        public ?string $notes,
    ) {}
}
