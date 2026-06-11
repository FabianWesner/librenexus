<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = Carbon::parse(fake()->dateTimeBetween('+1 day', '+20 days'))
            ->startOfHour();

        return [
            'team_id' => Team::factory(),
            'staff_id' => Staff::factory(),
            'service_id' => Service::factory(),
            'customer_id' => Customer::factory(),
            'status' => AppointmentStatus::Confirmed,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(60),
            'buffered_starts_at' => $startsAt,
            'buffered_ends_at' => $startsAt->addMinutes(60),
            'notes' => fake()->optional()->sentence(),
            'cancellation_token_hash' => hash('sha256', Str::random(64)),
        ];
    }

    /**
     * A specific UTC time range (buffered range matches unless overridden).
     */
    public function between(string $startsAt, string $endsAt): static
    {
        return $this->state(fn (): array => [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'buffered_starts_at' => $startsAt,
            'buffered_ends_at' => $endsAt,
        ]);
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function cancelled(): static
    {
        return $this->status(AppointmentStatus::Cancelled);
    }

    public function pending(): static
    {
        return $this->status(AppointmentStatus::Pending);
    }
}
