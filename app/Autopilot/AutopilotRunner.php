<?php

namespace App\Autopilot;

use App\Agent\AgentTasks;
use App\Ai\ClassifyTransactions;
use App\Enums\Actor;
use App\Enums\AutopilotLevel;
use App\Enums\TaxReportStatus;
use App\Models\Employee;
use App\Models\Mandate;
use App\Models\PayrollRun;
use App\Models\TaxReport;
use App\Payroll\PayrollService;
use App\Services\CurrentCompany;
use App\Services\Platform\KillSwitch;
use App\Submission\SubmissionOrchestrator;
use App\Submission\SubmissionRefused;
use App\Submission\SubmissionVerifier;
use App\Tax\MissingTaxRule;
use App\Tax\TaxObligations;
use App\Tax\TaxReportPreparer;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One autopilot pass for the current company (run hourly by the scheduler, inside the company's context).
 *
 *   level 1 prepare: classify transactions (rules, then AI proposals), draft last month's payroll, prepare due reports
 *   level 2 ask:     also asks the owner to approve every clean report (needs an active mandate)
 *   level 3 auto:    under the mandate the report is approved and submitted after the cancel window, if all gates pass
 *
 * Reports whose cancel window ended are submitted on every pass, whatever the level; the gates still decide.
 */
class AutopilotRunner
{
    public function __construct(
        private readonly CurrentCompany $company,
        private readonly TaxObligations $obligations,
        private readonly TaxReportPreparer $preparer,
        private readonly SubmissionOrchestrator $orchestrator,
        private readonly PayrollService $payroll,
        private readonly ClassifyTransactions $classify,
        private readonly SubmissionVerifier $verifier,
    ) {}

    /** @return array<string, int|string> what was done, for the command output */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $this->company->forget();
        $settings = $this->company->settings();
        $summary = ['level' => $settings->autopilot()->value, 'classified' => 0, 'payroll' => 0, 'prepared' => 0, 'requested' => 0, 'submitted' => 0, 'errors' => 0];

        $active = $settings->autopilot() !== AutopilotLevel::Off && ! $settings->isAutopilotPaused() && ! KillSwitch::isActive();

        if ($active) {
            $summary['classified'] = $this->classify($summary);
            $summary['payroll'] = $this->draftPayroll($today, $summary);
            $summary['prepared'] = $this->prepareDue($today, $summary);

            if ($settings->autopilot()->needsMandate() && Mandate::hasActive()) {
                $summary['requested'] = $this->requestApprovals($summary);
            }
        }

        $summary['submitted'] = $this->orchestrator->processDue();

        // Whatever the level: settle deliveries whose outcome is open. Verification never sends anything.
        try {
            $summary['agent_expired'] = app(AgentTasks::class)->expireLeases();
            $verified = $this->verifier->verifyPending();
            $summary['verified'] = $verified['settled'];
            $summary['interrupted'] = $verified['interrupted'];
        } catch (Throwable $e) {
            report($e);
            $summary['errors']++;
        }

        return $summary;
    }

    private function classify(array &$summary): int
    {
        try {
            return ($this->classify)();
        } catch (Throwable $e) {
            report($e);
            $summary['errors']++;

            return 0;
        }
    }

    private function draftPayroll(CarbonImmutable $today, array &$summary): int
    {
        $month = $today->startOfMonth()->subMonth();

        if (PayrollRun::query()->whereDate('period', $month->toDateString())->exists() || ! Employee::query()->where('status', 'active')->exists()) {
            return 0;
        }

        try {
            $this->payroll->prepare($month, Actor::Autopilot);

            return 1;
        } catch (Throwable $e) {
            report($e);
            $summary['errors']++;

            return 0;
        }
    }

    private function prepareDue(CarbonImmutable $today, array &$summary): int
    {
        $count = 0;

        foreach ($this->obligations->due($this->company->settings(), 31, $today) as $row) {
            if ($row['report'] !== null && in_array($row['report']->status, [TaxReportStatus::Scheduled, TaxReportStatus::AwaitingSignature], true)) {
                continue; // never recalculate under an approval in progress
            }

            try {
                $this->preparer->prepare($row['type'], $row['period'], Actor::Autopilot);
                $count++;
            } catch (SubmissionRefused) {
                // Nothing recorded for that period (or already sent): no report is made, and that is not an error.
            } catch (MissingTaxRule $e) {
                report($e);
                $summary['errors']++;
            } catch (Throwable $e) {
                report($e);
                $summary['errors']++;
            }
        }

        return $count;
    }

    private function requestApprovals(array &$summary): int
    {
        $count = 0;

        $reports = TaxReport::query()
            ->where('status', TaxReportStatus::Prepared)
            ->where('autopilot_hold', false)
            ->get()
            ->filter(fn (TaxReport $report) => $report->blockingIssues() === [] && $report->period_end->isBefore(today()));

        foreach ($reports as $report) {
            try {
                $this->orchestrator->requestSubmission($report, Actor::Autopilot);
                $count++;
            } catch (SubmissionRefused) {
                // State changed meanwhile; the next pass looks again.
            } catch (Throwable $e) {
                report($e);
                $summary['errors']++;
            }
        }

        return $count;
    }
}
