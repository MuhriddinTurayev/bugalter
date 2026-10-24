<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The tax rules are seeded everywhere: they are rules (from docs/tax), not demo data.
     *
     * Everything else here is DEMO data for local development. It is never seeded in production,
     * and the platform admin and demo companies are created only when APP_ENV=local.
     */
    public function run(): void
    {
        $this->call(TaxRuleSeeder::class);

        if (app()->isProduction()) {
            $this->command?->warn('Demo data is not seeded in production.');

            return;
        }

        $this->call([PlanSeeder::class, PlatformAdminSeeder::class, DemoIntegrationsSeeder::class]);

        // Single company dev mode keeps one demo company in the default database.
        if (config('app.single_company')) {
            $this->call([DemoSeeder::class, DemoAccountingSeeder::class]);
        }

        $this->call(DemoCompaniesSeeder::class);
    }
}
