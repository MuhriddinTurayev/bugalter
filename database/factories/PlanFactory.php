<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->unique()->words(2, true),
            'price_minor' => null,
            'billing_period' => 'month',
            'limits' => null,
            'is_active' => true,
        ];
    }
}
