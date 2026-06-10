<?php

namespace Database\Factories;

use App\Enums\CalendarColor;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'duration_minutes' => fake()->randomElement([15, 30, 45, 60, 90]),
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => fake()->randomElement([0, 5, 10, 15]),
            'price_minor' => fake()->numberBetween(10, 200) * 100,
            'color' => fake()->randomElement(CalendarColor::cases()),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the service is archived (hidden from booking, AC-3).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
