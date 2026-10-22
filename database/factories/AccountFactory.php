<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => AccountType::Bank,
            'currency' => 'UZS',
            'opening_balance_minor' => 0,
            'is_active' => true,
        ];
    }

    public function cash(): static
    {
        return $this->state(fn () => ['type' => AccountType::Cash]);
    }
}
