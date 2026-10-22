<?php

namespace Database\Factories;

use App\Enums\ContractorType;
use App\Models\Contractor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contractor>
 */
class ContractorFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' MChJ',
            'stir' => fake()->unique()->numerify('3########'),
            'type' => ContractorType::Customer,
            'phone' => '+998'.fake()->numerify('9########'),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }
}
