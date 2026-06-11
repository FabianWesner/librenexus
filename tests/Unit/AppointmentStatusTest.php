<?php

use App\Enums\AppointmentStatus;

/**
 * The FR-APPT-4 status model: which statuses reserve time, which are
 * terminal, and the exact allowed-transition matrix. Booking-domain logic
 * carries elevated coverage and mutation targets (test-plan.md).
 */
covers(AppointmentStatus::class);

test('only pending and confirmed reserve the staff members time', function () {
    expect(AppointmentStatus::Pending->reservesTime())->toBeTrue()
        ->and(AppointmentStatus::Confirmed->reservesTime())->toBeTrue()
        ->and(AppointmentStatus::Completed->reservesTime())->toBeFalse()
        ->and(AppointmentStatus::Cancelled->reservesTime())->toBeFalse()
        ->and(AppointmentStatus::NoShow->reservesTime())->toBeFalse();

    expect(AppointmentStatus::reservingValues())->toBe(['pending', 'confirmed']);
});

test('completed cancelled and no-show are terminal, active statuses are not', function () {
    expect(AppointmentStatus::Pending->isTerminal())->toBeFalse()
        ->and(AppointmentStatus::Confirmed->isTerminal())->toBeFalse()
        ->and(AppointmentStatus::Completed->isTerminal())->toBeTrue()
        ->and(AppointmentStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(AppointmentStatus::NoShow->isTerminal())->toBeTrue();
});

test('the transition matrix matches FR-APPT-4 exactly', function () {
    expect(AppointmentStatus::Pending->allowedTransitions())
        ->toBe([AppointmentStatus::Confirmed, AppointmentStatus::Cancelled, AppointmentStatus::NoShow])
        ->and(AppointmentStatus::Confirmed->allowedTransitions())
        ->toBe([AppointmentStatus::Completed, AppointmentStatus::Cancelled, AppointmentStatus::NoShow])
        ->and(AppointmentStatus::Completed->allowedTransitions())->toBe([])
        ->and(AppointmentStatus::Cancelled->allowedTransitions())->toBe([])
        ->and(AppointmentStatus::NoShow->allowedTransitions())->toBe([]);
});

test('every status pair resolves canTransitionTo consistently with the matrix', function () {
    $allowed = [
        'pending' => ['confirmed', 'cancelled', 'no_show'],
        'confirmed' => ['completed', 'cancelled', 'no_show'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    foreach (AppointmentStatus::cases() as $from) {
        foreach (AppointmentStatus::cases() as $to) {
            expect($from->canTransitionTo($to))
                ->toBe(in_array($to->value, $allowed[$from->value], true), "{$from->value} -> {$to->value}");
        }
    }
});

test('labels are human readable for every status', function () {
    expect(AppointmentStatus::Pending->label())->toBe('Pending')
        ->and(AppointmentStatus::Confirmed->label())->toBe('Confirmed')
        ->and(AppointmentStatus::Completed->label())->toBe('Completed')
        ->and(AppointmentStatus::Cancelled->label())->toBe('Cancelled')
        ->and(AppointmentStatus::NoShow->label())->toBe('No-show');
});
