<?php

namespace App\Console\Commands;

use Database\Seeders\TaxRuleSeeder;
use Illuminate\Console\Command;

/** Loads docs/tax/rules.json into the central rules tables. Verified rules whose value is unchanged stay verified. */
class SyncTaxRules extends Command
{
    protected $signature = 'tax:rules-sync';

    protected $description = 'Load the tax rules from docs/tax/rules.json into the rules tables';

    public function handle(TaxRuleSeeder $seeder): int
    {
        $result = $seeder->sync();

        $this->info(sprintf('Rates: %d, deadlines: %d, changed: %d.', $result['rates'], $result['deadlines'], $result['changed']));

        return self::SUCCESS;
    }
}
