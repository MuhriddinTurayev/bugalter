<?php

namespace Database\Seeders;

use App\Enums\PlatformRole;
use App\Models\PlatformStaff;
use Illuminate\Database\Seeder;

/**
 * Local development only: creates a platform admin and a support user with documented passwords.
 * Outside APP_ENV=local it does nothing, so no default password can ever exist elsewhere.
 * Real environments use `php artisan platform:create-admin`.
 */
class PlatformAdminSeeder extends Seeder
{
    /** Dev-only passwords, documented in the README. */
    public const ADMIN_PASSWORD = 'Platform#Dev2026';

    public const SUPPORT_PASSWORD = 'Support#Dev2026';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('PlatformAdminSeeder skipped: it only runs when APP_ENV=local.');

            return;
        }

        foreach ([
            ['Platform Admin', 'admin@bugalter.local', self::ADMIN_PASSWORD, PlatformRole::SuperAdmin],
            ['Platform Support', 'support@bugalter.local', self::SUPPORT_PASSWORD, PlatformRole::Support],
        ] as [$name, $email, $password, $role]) {
            $staff = PlatformStaff::query()->firstOrNew(['email' => $email]);
            $staff->fill(['name' => $name, 'password' => $password, 'role' => $role, 'is_active' => true])->save();
        }
    }
}
