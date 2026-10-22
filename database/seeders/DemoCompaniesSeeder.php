<?php

namespace Database\Seeders;

use App\Actions\SaveDocument;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Local development only: two real companies (demo1, demo2), each with its own database and demo data. */
class DemoCompaniesSeeder extends Seeder
{
    public function run(TenantProvisioningService $provisioning): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoCompaniesSeeder skipped: it only runs when APP_ENV=local.');

            return;
        }

        $plans = Plan::query()->orderBy('id')->get()->keyBy('key');

        $companies = [
            ['demo1', 'Namuna Savdo MChJ (DEMO)', '300000001', 'business', 0],
            ['demo2', 'Zamon Servis MChJ (DEMO)', '300000002', 'starter', 1],
        ];

        // `migrate:fresh` empties the central database but leaves company databases behind. The central
        // tenants table is empty at this point, so every company database is an orphan: drop them all.
        $this->dropOrphanedCompanyDatabases();

        foreach ($companies as [$slug, $name, $stir, $plan, $variant]) {
            $result = $provisioning->provision([
                'name' => $name,
                'stir' => $stir,
                'slug' => $slug,
                'plan_id' => $plans[$plan]->id,
                'owner_name' => 'Akmal Turayev',
                'owner_email' => 'owner@bugalter.test',
                'owner_phone' => '+998901112233',
            ]);

            /** @var Tenant $tenant */
            $tenant = $result['tenant'];

            $tenant->run(fn () => app(DemoSeeder::class)->forCompany($name, $variant)->run(app(SaveDocument::class)));
        }
    }

    private function dropOrphanedCompanyDatabases(): void
    {
        $connection = DB::connection(config('tenancy.database.central_connection'));
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], (string) config('tenancy.database.prefix')).'%';

        // SHOW statements cannot take bound parameters, so the pattern is quoted by the driver.
        foreach ($connection->select('SHOW DATABASES LIKE '.$connection->getPdo()->quote($pattern)) as $row) {
            $name = (string) array_values((array) $row)[0];

            $connection->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '', $name).'`');
        }
    }
}
