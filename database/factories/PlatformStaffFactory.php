<?php

namespace Database\Factories;

use App\Enums\PlatformRole;
use App\Models\PlatformStaff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<PlatformStaff>
 */
class PlatformStaffFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('Correct-Horse-9'),
            'role' => PlatformRole::SuperAdmin,
            'is_active' => true,
        ];
    }

    public function support(): static
    {
        return $this->state(fn () => ['role' => PlatformRole::Support]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Two-factor authentication already enrolled. */
    public function withTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => (new Google2FA)->generateSecretKey(32),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [],
        ]);
    }
}
