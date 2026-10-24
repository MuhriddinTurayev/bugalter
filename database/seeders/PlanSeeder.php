<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Sample plans so the panel has something to choose from. Prices are deliberately NOT set:
 * the platform owner enters them in the panel. The limits are sample values, also editable there.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['starter', 'Starter', ['max_users' => 3, 'max_documents_per_month' => 100]],
            ['business', 'Business', ['max_users' => 10, 'max_documents_per_month' => 1000]],
            ['enterprise', 'Enterprise', []],
        ];

        foreach ($plans as [$key, $name, $limits]) {
            Plan::query()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'description' => 'Namuna tarif: narx va cheklovlar panelda kiritiladi.',
                'limits' => $limits === [] ? null : $limits,
                'is_active' => true,
            ]);
        }
    }
}
