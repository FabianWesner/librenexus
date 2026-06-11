<?php

use App\Actions\Availability\GetBookableSlots;
use App\Data\CurrentTenant;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Auth;

/**
 * Demo seeder (Epic 09, FR-OPS-3, AC-3): `php artisan db:seed` produces an
 * explorable, genuinely bookable demo tenant with a deterministic manage
 * token and a reviewer login, and stays idempotent across repeated runs.
 */
function demoTeam(): Team
{
    return Team::query()->where('slug', 'demo-clinic')->firstOrFail();
}

test('seeding twice creates no duplicate demo tenants, staff, or appointments', function () {
    $this->seed();

    $team = demoTeam();
    $counts = fn (): array => [
        'teams' => Team::query()->where('slug', 'like', 'demo-clinic%')->count(),
        'staff' => Staff::query()->withoutGlobalScopes()->where('team_id', $team->id)->count(),
        'services' => Service::query()->withoutGlobalScopes()->where('team_id', $team->id)->count(),
        'rules' => AvailabilityRule::query()->withoutGlobalScopes()->where('team_id', $team->id)->count(),
        'appointments' => Appointment::query()->withoutGlobalScopes()->where('team_id', $team->id)->count(),
        'users' => User::query()->where('email', 'demo@librenexus.test')->count(),
    ];

    $firstRun = $counts();

    $this->seed();

    expect($counts())->toBe($firstRun)
        ->and($firstRun['teams'])->toBe(1)
        ->and($firstRun['users'])->toBe(1);
});

test('the demo tenant has bookable staff, services, availability, and sample appointments', function () {
    $this->seed();

    $team = demoTeam();

    app(CurrentTenant::class)->set($team);

    try {
        expect(Staff::query()->bookable()->count())->toBe(2)
            ->and(Service::query()->bookable()->count())->toBe(3)
            ->and(AvailabilityRule::query()->count())->toBe(10);

        // Explorable data: a realistic spread of sample appointments
        // (~25 plus the deterministic token appointment) across both
        // staff members, with past outcomes mixed in.
        $appointments = Appointment::query()->get();

        expect($appointments->count())->toBeGreaterThanOrEqual(20)
            ->and($appointments->pluck('staff_id')->unique()->count())->toBe(2)
            ->and($appointments->pluck('customer_id')->unique()->count())->toBeGreaterThanOrEqual(5)
            ->and($appointments->whereIn('status', [AppointmentStatus::Completed, AppointmentStatus::Cancelled, AppointmentStatus::NoShow])->count())->toBeGreaterThan(0);
    } finally {
        app(CurrentTenant::class)->clear();
    }
});

test('a demo service is genuinely bookable: the slot engine returns open slots in the next week', function () {
    $this->seed();

    $team = demoTeam();

    app(CurrentTenant::class)->set($team);

    try {
        $service = Service::query()->bookable()->orderBy('id')->firstOrFail();
        $from = CarbonImmutable::now($team->timezone)->addDay();

        $slots = app(GetBookableSlots::class)->handle(
            team: $team,
            service: $service,
            staff: null,
            fromDate: $from->format('Y-m-d'),
            untilDate: $from->addDays(6)->format('Y-m-d'),
        );

        expect($slots)->not->toBeEmpty();
    } finally {
        app(CurrentTenant::class)->clear();
    }
});

test('the deterministic token appointment exists, is future, confirmed, and hash-matched', function () {
    $this->seed();

    $appointment = Appointment::query()
        ->withoutGlobalScopes()
        ->where('cancellation_token_hash', hash('sha256', DemoSeeder::DEMO_MANAGE_TOKEN))
        ->firstOrFail();

    expect($appointment->team_id)->toBe(demoTeam()->id)
        ->and($appointment->status)->toBe(AppointmentStatus::Confirmed)
        ->and($appointment->starts_at->isFuture())->toBeTrue()
        ->and(Appointment::findByManageToken(DemoSeeder::DEMO_MANAGE_TOKEN)?->id)->toBe($appointment->id);
});

test('the demo owner can log in and owns the demo tenant', function () {
    $this->seed();

    expect(Auth::attempt(['email' => 'demo@librenexus.test', 'password' => 'password']))->toBeTrue();

    $user = User::query()->where('email', 'demo@librenexus.test')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->belongsToTeam(demoTeam()))->toBeTrue();
});

test('the demo seeder refuses to run in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    (new DemoSeeder)->run();

    app()->detectEnvironment(fn (): string => 'testing');

    expect(Team::query()->withoutGlobalScopes()->where('slug', 'demo-clinic')->exists())->toBeFalse();
});
