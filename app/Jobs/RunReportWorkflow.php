<?php

namespace App\Jobs;

use App\Models\ReportRun;
use App\Tax\ReportWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Prepare reports automatically" in the background. Runs inside the company's context (QueueTenancyBootstrapper).
 * Never retried by itself: the cabinet step signs a login with the company key.
 */
class RunReportWorkflow implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public readonly int $runId) {}

    public function handle(ReportWorkflow $workflow): void
    {
        $run = ReportRun::query()->find($this->runId);

        if ($run !== null && $run->status === 'queued') {
            $workflow->run($run);
        }
    }

    /** A timeout or a crash: the page must not keep showing "running". */
    public function failed(?Throwable $e): void
    {
        try {
            ReportRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
                'status' => 'failed',
                'error' => __('tax.workflow.errors.unexpected'),
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            // Outside the company context the run is marked stale by its age (ReportRun::STALE_MINUTES).
        }
    }
}
