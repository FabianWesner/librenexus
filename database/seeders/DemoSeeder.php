<?php

namespace Database\Seeders;

use App\Data\CurrentTenant;
use App\Enums\AppointmentStatus;
use App\Enums\CalendarColor;
use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Idempotent demo tenant for local exploration and the public quality
 * gates: /demo-clinic and /manage/demo-manage-token are part of
 * PUBLIC_PATHS so pa11y and Lighthouse can reach a real booking page and
 * a tokened manage page (specs/pages.md §Page → gate coverage).
 */
class DemoSeeder extends Seeder
{
    /**
     * The raw demo manage token. Intentionally non-secret: it exists so
     * the tooling gates have a stable, public manage URL. Real tokens are
     * high-entropy and never stored (SEC-TOKEN-1).
     */
    public const string DEMO_MANAGE_TOKEN = 'demo-manage-token';

    /**
     * Seed the demo tenant.
     */
    public function run(): void
    {
        // Demo data carries documented non-secret credentials; it must never
        // land in a production database (Epic 09 security review).
        if (app()->isProduction()) {
            return;
        }

        $team = Team::query()->firstOrCreate(['slug' => 'demo-clinic'], [
            'name' => 'Demo Clinic',
            'is_personal' => false,
            'timezone' => 'Europe/Berlin',
            'contact_email' => 'demo@librenexus.test',
        ]);

        app(CurrentTenant::class)->set($team);

        try {
            $staff = $this->seedStaff($team);
            $services = $this->seedServices($team, $staff);
            $this->seedAvailability($team, $staff);
            $this->seedDemoAppointment($team, $staff[0], $services[0]);
            $this->seedSampleAppointments($team, $staff, $services);
        } finally {
            app(CurrentTenant::class)->clear();
        }

        $this->seedDemoOwner($team);
    }

    /**
     * A login for reviewers (FR-OPS-3, AC-3): demo@librenexus.test with the
     * documented local password, owner of the demo tenant. Intentionally
     * non-secret demo credentials, like the demo manage token.
     */
    private function seedDemoOwner(Team $team): void
    {
        $user = User::query()->firstOrCreate(['email' => 'demo@librenexus.test'], [
            'name' => 'Demo Owner',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        if (! $user->belongsToTeam($team)) {
            $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
        }

        if ($user->current_team_id === null) {
            $user->forceFill(['current_team_id' => $team->id])->save();
        }
    }

    /**
     * @return list<Staff>
     */
    private function seedStaff(Team $team): array
    {
        return [
            Staff::query()->firstOrCreate(
                ['team_id' => $team->id, 'name' => 'Dana Demo'],
                ['color' => CalendarColor::Indigo, 'is_active' => true],
            ),
            Staff::query()->firstOrCreate(
                ['team_id' => $team->id, 'name' => 'Sam Sample'],
                ['color' => CalendarColor::Emerald, 'is_active' => true],
            ),
        ];
    }

    /**
     * @param  list<Staff>  $staff
     * @return list<Service>
     */
    private function seedServices(Team $team, array $staff): array
    {
        $definitions = [
            ['name' => 'Initial consultation', 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 15, 'price_minor' => 9000, 'color' => CalendarColor::Indigo],
            ['name' => 'Follow-up visit', 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 10, 'price_minor' => 4500, 'color' => CalendarColor::Emerald],
            ['name' => 'Quick check', 'duration_minutes' => 15, 'buffer_before_minutes' => 5, 'buffer_after_minutes' => 0, 'price_minor' => null, 'color' => CalendarColor::Amber],
        ];

        $staffIds = array_map(fn (Staff $member): int => $member->id, $staff);

        return array_map(function (array $definition) use ($team, $staffIds): Service {
            $service = Service::query()->firstOrCreate(
                ['team_id' => $team->id, 'name' => $definition['name']],
                [...$definition, 'is_active' => true],
            );

            $service->staff()->syncWithoutDetaching($staffIds);

            return $service;
        }, $definitions);
    }

    /**
     * Monday to Friday, 09:00 to 17:00, for both staff members.
     *
     * @param  list<Staff>  $staff
     */
    private function seedAvailability(Team $team, array $staff): void
    {
        foreach ($staff as $member) {
            foreach (range(1, 5) as $weekday) {
                AvailabilityRule::query()->firstOrCreate([
                    'team_id' => $team->id,
                    'staff_id' => $member->id,
                    'weekday' => $weekday,
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                ]);
            }
        }
    }

    /**
     * One future appointment whose manage token is the stable, documented
     * demo token, so /manage/demo-manage-token always resolves.
     */
    private function seedDemoAppointment(Team $team, Staff $staff, Service $service): void
    {
        $customer = Customer::query()->firstOrCreate(
            ['team_id' => $team->id, 'email' => 'demo.customer@librenexus.test'],
            ['name' => 'Demo Customer', 'phone' => '+49 30 123456'],
        );

        $startsAt = CarbonImmutable::now($team->timezone)
            ->addWeek()
            ->startOfWeek()
            ->setTime(10, 0)
            ->utc();
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        Appointment::query()->updateOrCreate(
            ['cancellation_token_hash' => hash('sha256', self::DEMO_MANAGE_TOKEN)],
            [
                'team_id' => $team->id,
                'staff_id' => $staff->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'status' => AppointmentStatus::Confirmed,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'buffered_starts_at' => $startsAt->subMinutes($service->buffer_before_minutes),
                'buffered_ends_at' => $endsAt->addMinutes($service->buffer_after_minutes),
                'notes' => 'Stable demo appointment for the accessibility and performance gates.',
            ],
        );
    }

    /**
     * Roughly 25 sample appointments spread over the past and next two
     * weeks across both staff members, so the dashboard, list, and
     * calendar are genuinely explorable (FR-OPS-3). Skipped entirely once
     * any non-token appointment exists, so repeated `db:seed` runs never
     * pile up rows and reviewer-made bookings are left alone (AC-3).
     *
     * @param  list<Staff>  $staff
     * @param  list<Service>  $services
     */
    private function seedSampleAppointments(Team $team, array $staff, array $services): void
    {
        if ($this->hasSampleAppointments($team)) {
            return;
        }

        $customers = $this->seedSampleCustomers($team);
        $today = CarbonImmutable::now($team->timezone)->startOfDay();
        $sequence = 0;

        foreach (range(-13, 13) as $offset) {
            $day = $today->addDays($offset);

            if ($day->isWeekend()) {
                continue;
            }

            foreach ($this->sampleStartHours($offset) as $position => $hour) {
                $this->createSampleAppointment(
                    $team,
                    $staff[(abs($offset) + $position) % count($staff)],
                    $services[$sequence % count($services)],
                    $customers[$sequence % count($customers)],
                    $day->setTime($hour, 0),
                    $this->sampleStatus($offset, $sequence),
                );

                $sequence++;
            }
        }
    }

    private function hasSampleAppointments(Team $team): bool
    {
        return Appointment::query()
            ->where('team_id', $team->id)
            ->where('cancellation_token_hash', '!=', hash('sha256', self::DEMO_MANAGE_TOKEN))
            ->exists();
    }

    /**
     * Start hours for one day: at most one appointment per staff member
     * per day and future hours from 13:00 only, so no sample can ever
     * overlap another sample or the 10:00 demo-token appointment in the
     * exclusion constraint (ADR-0003). Every third day gets two bookings.
     *
     * @return list<int>
     */
    private function sampleStartHours(int $dayOffset): array
    {
        $isBusyDay = $dayOffset % 3 === 0;

        if ($dayOffset < 0) {
            return $isBusyDay ? [9, 11] : [10];
        }

        return $isBusyDay ? [13, 15] : [16];
    }

    /**
     * Future samples stay confirmed; past samples mix in completed,
     * cancelled, and no-show outcomes (FR-OPS-3).
     */
    private function sampleStatus(int $dayOffset, int $sequence): AppointmentStatus
    {
        if ($dayOffset >= 0) {
            return AppointmentStatus::Confirmed;
        }

        $pastStatuses = [
            AppointmentStatus::Completed,
            AppointmentStatus::Completed,
            AppointmentStatus::Confirmed,
            AppointmentStatus::Cancelled,
            AppointmentStatus::NoShow,
        ];

        return $pastStatuses[$sequence % count($pastStatuses)];
    }

    private function createSampleAppointment(
        Team $team,
        Staff $staff,
        Service $service,
        Customer $customer,
        CarbonImmutable $startsAtLocal,
        AppointmentStatus $status,
    ): void {
        $startsAt = $startsAtLocal->utc();
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        Appointment::query()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'buffered_starts_at' => $startsAt->subMinutes($service->buffer_before_minutes),
            'buffered_ends_at' => $endsAt->addMinutes($service->buffer_after_minutes),
            'cancellation_token_hash' => hash('sha256', Str::random(64)),
        ]);
    }

    /**
     * @return list<Customer>
     */
    private function seedSampleCustomers(Team $team): array
    {
        $definitions = [
            ['name' => 'Anna Ahrens', 'email' => 'anna.ahrens@example.com', 'phone' => '+49 30 5550101'],
            ['name' => 'Ben Berger', 'email' => 'ben.berger@example.com', 'phone' => '+49 30 5550102'],
            ['name' => 'Clara Conrad', 'email' => 'clara.conrad@example.com', 'phone' => null],
            ['name' => 'David Dreyer', 'email' => 'david.dreyer@example.com', 'phone' => '+49 30 5550104'],
            ['name' => 'Emma Engel', 'email' => 'emma.engel@example.com', 'phone' => null],
            ['name' => 'Finn Fischer', 'email' => 'finn.fischer@example.com', 'phone' => '+49 30 5550106'],
        ];

        return array_map(fn (array $definition): Customer => Customer::query()->firstOrCreate(
            ['team_id' => $team->id, 'email' => $definition['email']],
            ['name' => $definition['name'], 'phone' => $definition['phone']],
        ), $definitions);
    }
}
