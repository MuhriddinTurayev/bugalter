<?php

namespace App\Submission;

use App\Jobs\SubmitApprovedReport;
use App\Models\TaxReport;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Str;

/**
 * "Tanlangan hisobotlarni tasdiqlash va topshirish": a person with reports.approve and reports.submit approves
 * the selected reports, each bound to the version hash the person saw on the review page, and every report is
 * then sent on its own (SubmitApprovedReport), so one failure never holds up or repeats another.
 *
 * All or nothing before approval: if any selected report changed, has blocking issues or cannot be approved,
 * none is approved and the person reviews again.
 */
class BatchApproval
{
    public function __construct(
        private readonly SubmissionOrchestrator $orchestrator,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<int, string>  $versions  report id => the version hash that was reviewed
     * @param  array{ip?: ?string, user_agent?: ?string}  $meta
     * @return array{batch: string, approved: list<int>, refused: array<int, string>}
     */
    public function __invoke(User $user, array $versions, array $meta = []): array
    {
        $reports = TaxReport::query()->whereIn('id', array_keys($versions))->get()->keyBy('id');
        $refused = [];

        foreach ($versions as $id => $hash) {
            $report = $reports[$id] ?? null;

            try {
                $report === null
                    ? throw new SubmissionRefused(__('tax.errors.report_missing'))
                    : $this->orchestrator->assertApprovable($report, $user, true, $hash);
            } catch (SubmissionRefused $e) {
                $refused[$id] = $e->getMessage();
            }
        }

        $batch = (string) Str::uuid();

        if ($refused !== []) {
            $this->audit->record('tax_report.batch_refused', ['batch' => $batch, 'reports' => array_keys($versions), 'refused' => array_keys($refused)], reason: implode('; ', $refused));

            return ['batch' => $batch, 'approved' => [], 'refused' => $refused];
        }

        $approved = [];

        foreach ($versions as $id => $hash) {
            try {
                $this->orchestrator->approveForImmediateSubmission($reports[$id], $user, $hash, $meta + ['batch' => $batch, 'declaration' => true]);
                $approved[] = $id;
            } catch (SubmissionRefused $e) {
                // Changed in the moment between the check and the approval (the books were updated).
                $refused[$id] = $e->getMessage();
            }
        }

        $this->audit->record('tax_report.batch_approved', ['batch' => $batch, 'approved' => $approved, 'refused' => array_keys($refused)]);

        // Sent only after every approval is recorded, each on its own.
        foreach ($approved as $id) {
            SubmitApprovedReport::dispatch($id, $user->name);
        }

        return ['batch' => $batch, 'approved' => $approved, 'refused' => $refused];
    }
}
