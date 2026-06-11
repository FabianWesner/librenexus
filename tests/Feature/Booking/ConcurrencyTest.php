<?php

use App\Actions\Booking\BookAppointment;
use App\Data\BookingRequest;
use App\Data\CurrentTenant;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotNoLongerAvailableException;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use PgSql\Connection;

/**
 * FR-BOOK-3 named regression suite: double-booking is impossible, enforced
 * by the Postgres exclusion constraint (ADR-0003), proven under genuine
 * concurrency with two database connections racing the same slot, plus the
 * application-level paths on top of it.
 */
covers(BookAppointment::class, AppointmentStatus::class);

/**
 * Open a raw, independent PostgreSQL connection to the test database.
 */
function rawPgConnection(): Connection
{
    $config = config('database.connections.pgsql');

    $connection = pg_connect(sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['username'],
        $config['password'],
    ), PGSQL_CONNECT_FORCE_NEW);

    if ($connection === false) {
        throw new RuntimeException('Could not open a raw pgsql connection for the concurrency test.');
    }

    return $connection;
}

/**
 * @return array{team: int, staff: int, service: int, customer: int}
 */
function insertConcurrencyFixtures(Connection $connection): array
{
    $teamId = (int) pg_fetch_result(pg_query($connection, "
        INSERT INTO teams (name, slug, is_personal, created_at, updated_at)
        VALUES ('Race Clinic', 'race-clinic-".bin2hex(random_bytes(4))."', false, now(), now()) RETURNING id
    "), 0, 0);

    $staffId = (int) pg_fetch_result(pg_query($connection, "
        INSERT INTO staff (team_id, name, color, is_active, created_at, updated_at)
        VALUES ({$teamId}, 'Racer', 'indigo', true, now(), now()) RETURNING id
    "), 0, 0);

    $serviceId = (int) pg_fetch_result(pg_query($connection, "
        INSERT INTO services (team_id, name, duration_minutes, buffer_before_minutes, buffer_after_minutes, color, is_active, created_at, updated_at)
        VALUES ({$teamId}, 'Consultation', 60, 0, 0, 'indigo', true, now(), now()) RETURNING id
    "), 0, 0);

    $customerId = (int) pg_fetch_result(pg_query($connection, "
        INSERT INTO customers (team_id, name, email, created_at, updated_at)
        VALUES ({$teamId}, 'Racing Customer', 'race-".bin2hex(random_bytes(4))."@example.com', now(), now()) RETURNING id
    "), 0, 0);

    return ['team' => $teamId, 'staff' => $staffId, 'service' => $serviceId, 'customer' => $customerId];
}

function appointmentInsertSql(array $fixtures, string $start, string $end, string $status = 'confirmed'): string
{
    $tokenHash = hash('sha256', bin2hex(random_bytes(32)));

    return "
        INSERT INTO appointments
            (team_id, staff_id, service_id, customer_id, status, starts_at, ends_at,
             buffered_starts_at, buffered_ends_at, cancellation_token_hash, created_at, updated_at)
        VALUES
            ({$fixtures['team']}, {$fixtures['staff']}, {$fixtures['service']}, {$fixtures['customer']},
             '{$status}', '{$start}', '{$end}', '{$start}', '{$end}', '{$tokenHash}', now(), now())
    ";
}

function cleanupConcurrencyFixtures(Connection $connection, int $teamId): void
{
    pg_query($connection, "DELETE FROM teams WHERE id = {$teamId}");
}

test('two genuinely concurrent bookings for the same slot: exactly one wins', function () {
    $writer = rawPgConnection();
    $racer = rawPgConnection();

    $fixtures = insertConcurrencyFixtures($writer);

    try {
        $start = '2027-03-10 09:00:00+00';
        $end = '2027-03-10 10:00:00+00';

        pg_query($writer, 'BEGIN');
        pg_query($racer, 'BEGIN');

        // First booking inserts inside an open transaction: the exclusion
        // constraint locks the range but nothing is committed yet.
        $firstInsert = pg_query($writer, appointmentInsertSql($fixtures, $start, $end));
        expect($firstInsert)->not->toBeFalse();

        // Second booking races the same range on its own connection. The
        // async send returns immediately while the server blocks the insert
        // on the first transaction's outcome: a genuine in-flight conflict.
        pg_send_query($racer, appointmentInsertSql($fixtures, $start, $end));

        // Commit the first booking: the blocked insert now resolves.
        pg_query($writer, 'COMMIT');

        $raceResult = pg_get_result($racer);
        $sqlState = pg_result_error_field($raceResult, PGSQL_DIAG_SQLSTATE);
        pg_query($racer, 'ROLLBACK');

        // 23P01 = exclusion_violation: the loser gets a constraint error,
        // never a second row.
        expect($sqlState)->toBe('23P01');

        $count = (int) pg_fetch_result(pg_query($writer,
            "SELECT count(*) FROM appointments WHERE staff_id = {$fixtures['staff']}"
        ), 0, 0);

        expect($count)->toBe(1);
    } finally {
        cleanupConcurrencyFixtures($writer, $fixtures['team']);
        pg_close($writer);
        pg_close($racer);
    }
});

test('concurrent bookings with partially overlapping ranges: exactly one wins', function () {
    $writer = rawPgConnection();
    $racer = rawPgConnection();

    $fixtures = insertConcurrencyFixtures($writer);

    try {
        pg_query($writer, 'BEGIN');
        pg_query($racer, 'BEGIN');

        pg_query($writer, appointmentInsertSql($fixtures, '2027-03-11 09:00:00+00', '2027-03-11 10:00:00+00'));
        pg_send_query($racer, appointmentInsertSql($fixtures, '2027-03-11 09:30:00+00', '2027-03-11 10:30:00+00'));

        pg_query($writer, 'COMMIT');

        $raceResult = pg_get_result($racer);
        $sqlState = pg_result_error_field($raceResult, PGSQL_DIAG_SQLSTATE);
        pg_query($racer, 'ROLLBACK');

        expect($sqlState)->toBe('23P01');

        $count = (int) pg_fetch_result(pg_query($writer,
            "SELECT count(*) FROM appointments WHERE staff_id = {$fixtures['staff']}"
        ), 0, 0);

        expect($count)->toBe(1);
    } finally {
        cleanupConcurrencyFixtures($writer, $fixtures['team']);
        pg_close($writer);
        pg_close($racer);
    }
});

test('a cancelled appointment does not block a concurrent booking at the same time', function () {
    $writer = rawPgConnection();

    $fixtures = insertConcurrencyFixtures($writer);

    try {
        // A cancelled row occupies the range on paper but is outside the
        // partial constraint (FR-APPT-4, FR-CANCEL-4).
        pg_query($writer, appointmentInsertSql($fixtures, '2027-03-12 09:00:00+00', '2027-03-12 10:00:00+00', 'cancelled'));

        $secondInsert = pg_query($writer, appointmentInsertSql($fixtures, '2027-03-12 09:00:00+00', '2027-03-12 10:00:00+00', 'confirmed'));

        expect($secondInsert)->not->toBeFalse();
    } finally {
        cleanupConcurrencyFixtures($writer, $fixtures['team']);
        pg_close($writer);
    }
});

describe('application-level booking paths', function () {
    beforeEach(function () {
        $this->team = Team::factory()->create(['timezone' => 'UTC']);
        app(CurrentTenant::class)->set($this->team);

        $this->staff = Staff::factory()->create(['team_id' => $this->team->id]);
        $this->service = Service::factory()->create([
            'team_id' => $this->team->id,
            'duration_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
        ]);
        $this->service->staff()->attach($this->staff);

        AvailabilityRule::factory()->create([
            'team_id' => $this->team->id,
            'staff_id' => $this->staff->id,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        // 2027-03-08 is a Monday.
        $this->slotStart = CarbonImmutable::parse('2027-03-08T09:00:00', 'UTC');
        $this->travelTo($this->slotStart->subDays(3));
    });

    function bookingRequestFor(CarbonImmutable $startsAt, $service, ?int $staffId, string $email = 'alice@example.com'): BookingRequest
    {
        return new BookingRequest(
            serviceId: $service->id,
            staffId: $staffId,
            startsAt: $startsAt,
            customerName: 'Alice Example',
            customerEmail: $email,
            customerPhone: null,
            notes: null,
        );
    }

    test('booking the same slot twice: the second gets a clear error and persists nothing', function () {
        $action = app(BookAppointment::class);

        $first = $action->handle($this->team, bookingRequestFor($this->slotStart, $this->service, $this->staff->id));

        expect($first->appointment->status)->toBe(AppointmentStatus::Confirmed);

        expect(fn () => $action->handle($this->team, bookingRequestFor($this->slotStart, $this->service, $this->staff->id, 'bob@example.com')))
            ->toThrow(SlotNoLongerAvailableException::class, 'no longer available');

        expect(Appointment::query()->count())->toBe(1)
            ->and(Customer::query()->where('email', 'bob@example.com')->exists())->toBeFalse();
    });

    test('the exclusion constraint also stops overlapping inserts through Eloquent', function () {
        Appointment::factory()
            ->for($this->team, 'team')->for($this->staff, 'staff')->for($this->service, 'service')
            ->between('2027-03-08 09:00:00+00', '2027-03-08 10:00:00+00')
            ->create(['customer_id' => Customer::factory()->create(['team_id' => $this->team->id])->id]);

        expect(fn () => Appointment::factory()
            ->for($this->team, 'team')->for($this->staff, 'staff')->for($this->service, 'service')
            ->between('2027-03-08 09:30:00+00', '2027-03-08 10:30:00+00')
            ->create(['customer_id' => Customer::factory()->create(['team_id' => $this->team->id])->id]))
            ->toThrow(QueryException::class);
    });

    test('transitioning a held slot to a non-reserving status frees it immediately', function () {
        $action = app(BookAppointment::class);

        $held = $action->handle($this->team, bookingRequestFor($this->slotStart, $this->service, $this->staff->id));

        // While held, rebooking fails.
        expect(fn () => $action->handle($this->team, bookingRequestFor($this->slotStart, $this->service, $this->staff->id, 'carol@example.com')))
            ->toThrow(SlotNoLongerAvailableException::class);

        // The data-level transition (AC-5): no cancel UI exists yet.
        $held->appointment->update(['status' => AppointmentStatus::Cancelled]);

        $rebooked = $action->handle($this->team, bookingRequestFor($this->slotStart, $this->service, $this->staff->id, 'carol@example.com'));

        expect($rebooked->appointment->starts_at->equalTo($this->slotStart))->toBeTrue()
            ->and(Appointment::query()->reservingTime()->count())->toBe(1);
    });

    test('a no-show appointment does not block a new booking at the same time', function () {
        Appointment::factory()
            ->for($this->team, 'team')->for($this->staff, 'staff')->for($this->service, 'service')
            ->status(AppointmentStatus::NoShow)
            ->between('2027-03-08 09:00:00+00', '2027-03-08 10:00:00+00')
            ->create(['customer_id' => Customer::factory()->create(['team_id' => $this->team->id])->id]);

        $booked = app(BookAppointment::class)
            ->handle($this->team, bookingRequestFor($this->slotStart, $this->service, $this->staff->id));

        expect($booked->appointment->status)->toBe(AppointmentStatus::Confirmed);
    });

    test('any-available picks the lowest staff id deterministically and cannot conflict', function () {
        $secondStaff = Staff::factory()->create(['team_id' => $this->team->id]);
        $this->service->staff()->attach($secondStaff);
        AvailabilityRule::factory()->create([
            'team_id' => $this->team->id,
            'staff_id' => $secondStaff->id,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $action = app(BookAppointment::class);

        $first = $action->handle($this->team, bookingRequestFor($this->slotStart, $this->service, null));
        $second = $action->handle($this->team, bookingRequestFor($this->slotStart, $this->service, null, 'second@example.com'));

        expect($first->appointment->staff_id)->toBe($this->staff->id)
            ->and($second->appointment->staff_id)->toBe($secondStaff->id)
            ->and($first->appointment->starts_at->equalTo($second->appointment->starts_at))->toBeTrue();
    });

    test('booking with approval mode creates a pending appointment that still reserves the slot', function () {
        $this->team->update(['requires_approval' => true]);

        $action = app(BookAppointment::class);

        $held = $action->handle($this->team, bookingRequestFor($this->slotStart, $this->service, $this->staff->id));

        expect($held->appointment->status)->toBe(AppointmentStatus::Pending);

        expect(fn () => $action->handle($this->team, bookingRequestFor($this->slotStart, $this->service, $this->staff->id, 'late@example.com')))
            ->toThrow(SlotNoLongerAvailableException::class);
    });
});
