<?php

namespace App\Jobs;

use App\Enums\Actor;
use App\Enums\TaxReportStatus;
use App\Models\TaxReport;
use App\Submission\SubmissionOrchestrator;
use App\Submission\SubmissionRefused;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one report a person approved for immediate submission (batch approval). The payload holds only the
 * report id and the approver's name. Never retried: a delivery that died half-way is left "sending", and
 * SubmissionVerifier turns it into "unknown" instead of sending it again.
 */
class SubmitApprovedReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $reportId,
        public readonly string $approverName,
    ) {}

    public function handle(SubmissionOrchestrator $orchestrator): void
    {
        $report = TaxReport::query()->find($this->reportId);

        // Only an immediate approval by a person that is still valid; anything else changed meanwhile.
        if ($report === null || $report->status !== TaxReportStatus::Scheduled || $report->approval_source !== 'user'
            || $report->scheduled_at !== null || ! $report->isApprovalValid()) {
            return;
        }

        try {
            $orchestrator->submit($report, Actor::User, $this->approverName);
        } catch (SubmissionRefused) {
            // Another run is sending it, or it was sent already.
        }
    }
}
