<?php

namespace App\Console\Commands;

use App\Autopilot\DailyReminders;
use App\Support\ForEachCompany;
use Illuminate\Console\Command;
use Throwable;

/** Daily: overdue invoices, tax deadlines and ERI key expiry for every company. */
class RunDailyReminders extends Command
{
    protected $signature = 'bugalter:daily {--company= : Only this company (slug)}';

    protected $description = 'Send daily reminders and check signing key expiry for every company';

    public function handle(): int
    {
        $results = ForEachCompany::run(function (string $company) {
            try {
                return app(DailyReminders::class)->run();
            } catch (Throwable $e) {
                report($e);

                return ['error' => $e->getMessage()];
            }
        }, $this->option('company'));

        foreach ($results as $company => $summary) {
            $this->line(sprintf('%-20s %s', $company, json_encode($summary)));
        }

        return self::SUCCESS;
    }
}
