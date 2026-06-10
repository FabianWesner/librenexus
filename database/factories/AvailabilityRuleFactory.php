<?php

namespace Database\Factories;

use App\Models\AvailabilityRule;
use App\Models\Staff;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityRule>
 */
class AvailabilityRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'staff_id' => Staff::factory(),
            'weekday' => fake()->numberBetween(1, 7),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ];
    }

    /**
     * A rule on the given ISO weekday with the given window.
     */
    public function window(int $weekday, string $start, string $end): static
    {
        return $this->state(fn (): array => [
            'weekday' => $weekday,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }
}
