<?php

namespace Database\Factories;

use App\Enums\CalendarColor;
use App\Models\Membership;
use App\Models\Staff;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'membership_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'color' => fake()->randomElement(CalendarColor::cases()),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the staff member is deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Link the staff record to the given team membership (FR-STAFF-4).
     */
    public function linkedTo(Membership $membership): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $membership->team_id,
            'membership_id' => $membership->id,
        ]);
    }
}
