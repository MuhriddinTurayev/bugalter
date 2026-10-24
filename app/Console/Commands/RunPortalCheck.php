<?php

namespace App\Console\Commands;

use App\Agent\AgentTasks;
use App\Enums\Actor;
use App\Enums\AutopilotLevel;
use App\Models\DesktopAgent;
use App\Portal\TaxPortalCheck;
use App\Services\CurrentCompany;
use App\Services\Platform\KillSwitch;
use App\Submission\Gateway\DeliversThroughAgent;
use App\Submission\Gateway\TaxAuthorityGateway;
use App\Support\ForEachCompany;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily: the autopilot visits the tax cabinet of every company whose owner allowed it (portal access on,
 * autopilot at level 1 or higher, not stopped), prepares what is missing and asks for approval.
 */
class RunPortalCheck extends Command
{
    protected $signature = 'bugalter:portal-check {--company= : Only this company (slug)}';

    protected $description = 'Check the tax cabinet of every company that allowed it and prepare missing reports';

    public function handle(): int
    {
        $results = ForEachCompany::run(function (string $company) {
            $current = app(CurrentCompany::class);
            $current->forget();
            $settings = $current->settings();

            if (! $settings->portal_access || $settings->autopilot() === AutopilotLevel::Off || $settings->isAutopilotPaused() || KillSwitch::isActive()) {
                return ['skipped' => true];
            }

            try {
                // With the desktop agent the visit happens on the accountant's computer, whenever it is next online.
                if (app(TaxAuthorityGateway::class) instanceof DeliversThroughAgent) {
                    return DesktopAgent::query()->whereNull('revoked_at')->exists()
                        ? ['queued_for_agent' => app(AgentTasks::class)->requestPortalCheck(Actor::Autopilot)->id]
                        : ['skipped' => 'no_agent'];
                }

                $check = app(TaxPortalCheck::class)->run(Actor::Autopilot);

                return ['status' => $check->status] + ($check->summary ?? []);
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
