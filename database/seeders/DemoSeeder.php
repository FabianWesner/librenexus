<?php

namespace Database\Seeders;

use App\Data\CurrentTenant;
use App\Enums\AppointmentStatus;
use App\Enums\CalendarColor;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

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
        } finally {
            app(CurrentTenant::class)->clear();
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
}
