<?php

namespace App\Console\Commands;

use App\Autopilot\AutopilotRunner;
use App\Support\ForEachCompany;
use Illuminate\Console\Command;
use Throwable;

/** Hourly: the autopilot pass and the submission of reports whose cancel window ended, for every company. */
class RunAutopilot extends Command
{
    protected $signature = 'bugalter:autopilot {--company= : Only this company (slug)}';

    protected $description = 'Run the autopilot and process due submissions for every company';

    public function handle(): int
    {
        $results = ForEachCompany::run(function (string $company) {
            try {
                return app(AutopilotRunner::class)->run();
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
