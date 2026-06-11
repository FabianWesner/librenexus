<?php

use App\Actions\Availability\ComputeSlots;
use App\Actions\Availability\GetBookableSlots;
use App\Actions\Booking\BookAppointment;
use App\Data\BookedAppointment;
use App\Data\BookingRequest;
use App\Data\CurrentTenant;
use App\Data\Slot;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotNoLongerAvailableException;
use App\Http\Middleware\ResolvePublicTenant;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;

/**
 * Booking is critical domain logic (test-plan.md): these tests pin down the
 * token format (SEC-TOKEN-1), customer normalization, mail branding, and the
 * public tenant resolution that the flow tests do not assert directly.
 */
covers(BookAppointment::class, AppointmentConfirmationMail::class, ResolvePublicTenant::class, Customer::class);

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

function hardeningRequest(CarbonImmutable $startsAt, $service, ?int $staffId, array $overrides = []): BookingRequest
{
    return new BookingRequest(
        serviceId: $service->id,
        staffId: $staffId,
        startsAt: $startsAt,
        customerName: $overrides['name'] ?? 'Alice Example',
        customerEmail: $overrides['email'] ?? 'alice@example.com',
        customerPhone: $overrides['phone'] ?? null,
        notes: $overrides['notes'] ?? null,
    );
}

test('the manage token carries at least 32 bytes of entropy in a fixed format', function () {
    $first = app(BookAppointment::class)
        ->handle($this->team, hardeningRequest($this->slotStart, $this->service, $this->staff->id));

    $second = app(BookAppointment::class)
        ->handle($this->team, hardeningRequest($this->slotStart->addHours(2), $this->service, $this->staff->id, ['email' => 'two@example.com']));

    // 48 lowercase alphanumerics + 16 hex chars = 64 chars, > 32 bytes of
    // CSPRNG entropy (SEC-TOKEN-1); only the hash is stored.
    foreach ([$first, $second] as $booked) {
        expect($booked->rawManageToken)->toMatch('/\A[a-z0-9]{48}[a-f0-9]{16}\z/')
            ->and($booked->appointment->cancellation_token_hash)->toBe(hash('sha256', $booked->rawManageToken));
    }

    expect($first->rawManageToken)->not->toBe($second->rawManageToken);
});

test('booking notes are persisted on the appointment', function () {
    $booked = app(BookAppointment::class)->handle(
        $this->team,
        hardeningRequest($this->slotStart, $this->service, $this->staff->id, ['notes' => 'Door code 4711']),
    );

    expect($booked->appointment->refresh()->notes)->toBe('Door code 4711');
});

test('customer emails are trimmed and lowercased before matching', function () {
    Customer::factory()->create(['team_id' => $this->team->id, 'email' => 'alice@example.com']);

    $booked = app(BookAppointment::class)->handle(
        $this->team,
        hardeningRequest($this->slotStart, $this->service, $this->staff->id, ['email' => '  ALICE@Example.com ']),
    );

    expect(Customer::query()->count())->toBe(1)
        ->and($booked->appointment->customer->email)->toBe('alice@example.com');
});

test('the confirmation mail is branded and uses the tenant reply-to when set', function () {
    $this->team->update(['contact_email' => 'desk@clinic.example', 'name' => 'Branding Clinic']);

    $booked = app(BookAppointment::class)
        ->handle($this->team->refresh(), hardeningRequest($this->slotStart, $this->service, $this->staff->id));

    $mail = new AppointmentConfirmationMail($booked->appointment, $booked->rawManageToken);
    $envelope = $mail->envelope();

    expect($envelope->subject)->toBe('Your appointment at Branding Clinic is confirmed')
        ->and($envelope->replyTo)->toHaveCount(1)
        ->and($envelope->replyTo[0]->address)->toBe('desk@clinic.example')
        ->and($mail->render())->toContain('Branding Clinic')
        ->toContain($booked->rawManageToken);
});

test('the confirmation mail for pending approval says so and omits reply-to without contact email', function () {
    $this->team->update(['requires_approval' => true, 'contact_email' => null]);

    $booked = app(BookAppointment::class)
        ->handle($this->team->refresh(), hardeningRequest($this->slotStart, $this->service, $this->staff->id));

    expect($booked->appointment->status)->toBe(AppointmentStatus::Pending);

    $mail = new AppointmentConfirmationMail($booked->appointment, $booked->rawManageToken);
    $envelope = $mail->envelope();

    expect($envelope->subject)->toBe('Your appointment request at '.$this->team->name)
        ->and($envelope->replyTo)->toBe([]);
});

test('the customer model itself normalizes email on assignment', function () {
    $customer = Customer::factory()->create([
        'team_id' => $this->team->id,
        'email' => '  Padded@Example.COM ',
    ]);

    expect($customer->refresh()->email)->toBe('padded@example.com');
});

/**
 * @return QueryException with SQLSTATE 23505
 */
function uniqueViolationException(): QueryException
{
    $pdoException = new PDOException('duplicate key value violates unique constraint');
    $pdoException->errorInfo = ['23505', 7, 'duplicate key value'];

    $codeProperty = new ReflectionProperty(Exception::class, 'code');
    $codeProperty->setValue($pdoException, '23505');

    return new QueryException('pgsql', 'insert into "customers" ...', [], $pdoException);
}

test('a customer unique-violation race is retried once and succeeds', function () {
    $raceOnce = new class(app(GetBookableSlots::class)) extends BookAppointment
    {
        public int $attempts = 0;

        protected function attempt(Team $team, BookingRequest $request): BookedAppointment
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw uniqueViolationException();
            }

            return parent::attempt($team, $request);
        }
    };

    $booked = $raceOnce->handle($this->team, hardeningRequest($this->slotStart, $this->service, $this->staff->id));

    expect($raceOnce->attempts)->toBe(2)
        ->and($booked->appointment->exists)->toBeTrue()
        ->and(Customer::query()->count())->toBe(1);
});

test('a non-unique-violation query exception is rethrown, not retried', function () {
    $alwaysFails = new class(app(GetBookableSlots::class)) extends BookAppointment
    {
        public int $attempts = 0;

        protected function attempt(Team $team, BookingRequest $request): BookedAppointment
        {
            $this->attempts++;

            $pdoException = new PDOException('connection lost');
            $pdoException->errorInfo = ['08006', 7, 'connection lost'];

            $codeProperty = new ReflectionProperty(Exception::class, 'code');
            $codeProperty->setValue($pdoException, '08006');

            throw new QueryException('pgsql', 'insert ...', [], $pdoException);
        }
    };

    expect(fn () => $alwaysFails->handle($this->team, hardeningRequest($this->slotStart, $this->service, $this->staff->id)))
        ->toThrow(QueryException::class);

    expect($alwaysFails->attempts)->toBe(1);
});

test('a constraint-level lost race is translated to the friendly slot error', function () {
    // Occupy the slot for real.
    app(BookAppointment::class)->handle($this->team, hardeningRequest($this->slotStart, $this->service, $this->staff->id));

    // An engine that wrongly reports the slot as free simulates the race
    // window after re-validation: the exclusion constraint must be the
    // final arbiter and the 23P01 must surface as the friendly error.
    $blindEngine = new class(app(ComputeSlots::class)) extends GetBookableSlots
    {
        public function handle(
            Team $team,
            Service $service,
            ?Staff $staff,
            string $fromDate,
            string $untilDate,
            ?CarbonImmutable $now = null,
            ?int $excludeAppointmentId = null,
        ): Collection {
            $start = CarbonImmutable::parse($fromDate.'T09:00:00', 'UTC');

            return collect([new Slot(
                staffId: (int) $staff?->id,
                startsAt: $start,
                endsAt: $start->addMinutes(60),
                bufferedStartsAt: $start,
                bufferedEndsAt: $start->addMinutes(60),
            )]);
        }
    };

    $racingAction = new BookAppointment($blindEngine);

    expect(fn () => $racingAction->handle($this->team, hardeningRequest($this->slotStart, $this->service, $this->staff->id, ['email' => 'loser@example.com'])))
        ->toThrow(SlotNoLongerAvailableException::class, 'no longer available');

    expect(Appointment::query()->count())->toBe(1);
});

test('the public tenant middleware sets the context and shares the team', function () {
    app(CurrentTenant::class)->clear();

    $request = Request::create('/'.$this->team->slug);
    $request->setRouteResolver(function () {
        $route = new Route(['GET'], '/{tenant}', []);
        $route->bind(Request::create('/'.$this->team->slug));
        $route->setParameter('tenant', $this->team->slug);

        return $route;
    });

    $response = (new ResolvePublicTenant)->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok')
        ->and(app(CurrentTenant::class)->id())->toBe($this->team->id)
        ->and($request->attributes->get('publicTenant')?->id)->toBe($this->team->id);
});
