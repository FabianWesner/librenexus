<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\Team;
use App\Models\TimeOff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeOff>
 */
class TimeOffFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+10 days');

        return [
            'team_id' => Team::factory(),
            'staff_id' => Staff::factory(),
            'starts_at' => $start,
            'ends_at' => (clone $start)->modify('+4 hours'),
            'reason' => fake()->optional()->sentence(3),
        ];
    }
}
