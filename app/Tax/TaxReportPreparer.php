<?php

namespace App\Tax;

use App\Enums\Actor;
use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Models\ReportApproval;
use App\Models\TaxReport;
use App\Services\Audit;
use App\Services\CurrentCompany;
use App\Submission\SubmissionRefused;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Creates or refreshes the report of a type and period from the deterministic calculators. A report that was
 * already sent is never recalculated; an approval is voided when the figures change (PLAN section 5, step 3).
 */
class TaxReportPreparer
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly TaxRuleBook $rules,
        private readonly CurrentCompany $company,
        private readonly Audit $audit,
    ) {}

    public function prepare(TaxReportType $type, TaxPeriod $period, Actor $actor = Actor::User, ?string $actorName = null): TaxReport
    {
        return $this->store($type, $period, 'books', $actor, $actorName, function () use ($type, $period) {
            $deadline = $this->rules->deadline($type, $period->end);

            return [
                $this->calculator->compute($type, $period, $this->company->settings()),
                $deadline?->dueDateFor($period->end)->toDateString(),
                $deadline?->reference(),
            ];
        });
    }

    /**
     * The report as the tax authority's own reporting system calculated it (from e-invoices and online cash registers) and the
     * agent read it from the real form. Bugalter checks its arithmetic and shows how it differs from the books; a person approves
     * exactly these figures. A report like this is never submitted by a mandate (see SubmissionGates).
     *
     * @param  array<string, mixed>  $draft  PortalSnapshot::normalize()['drafts'][n]
     */
    public function prepareFromCabinet(TaxReportType $type, TaxPeriod $period, array $draft, Actor $actor = Actor::User, ?string $actorName = null): TaxReport
    {
        return $this->store($type, $period, 'cabinet', $actor, $actorName, fn () => [
            CabinetReport::computation($type, $period, $draft, $this->rules),
            $draft['due_date'] ?? null,
            null,
        ]);
    }

    /** @param  Closure(): array{0: TaxComputation, 1: ?string, 2: ?array<string, mixed>}  $compute */
    private function store(TaxReportType $type, TaxPeriod $period, string $source, Actor $actor, ?string $actorName, Closure $compute): TaxReport
    {
        return DB::transaction(function () use ($type, $period, $source, $actor, $actorName, $compute) {
            $report = TaxReport::query()
                ->where('type', $type->value)
                ->whereDate('period_start', $period->start->toDateString())
                ->lockForUpdate()
                ->first();

            if ($report !== null && $report->status->isFinal()) {
                throw new SubmissionRefused(__('tax.errors.already_submitted'));
            }

            // Figures read from the cabinet are refreshed only by reading the cabinet again, never by the books' calculator.
            if ($report !== null && $report->source === 'cabinet' && $source === 'books') {
                throw new SubmissionRefused(__('tax.errors.cabinet_source'));
            }

            [$computation, $dueDate, $deadlineReference] = $compute();
            // A period with no records at all gets no report row: a "0 so'm" return would be invented, not computed.
            if ($source === 'books' && collect($computation->issues)->contains(fn (array $issue) => $issue['key'] === 'no_books')) {
                throw new SubmissionRefused(__('tax.issues.no_books'));
            }

            $hash = $computation->versionHash();

            $attributes = [
                'source' => $source,
                'period_end' => $period->end->toDateString(),
                'due_date' => $dueDate,
                'base_minor' => $computation->baseMinor,
                'tax_minor' => $computation->taxMinor,
                'payable_minor' => $computation->payableMinor,
                'lines' => $computation->lines,
                'rules' => array_values(array_filter([...$computation->rules, $deadlineReference])),
                'issues' => $computation->issues,
                'version_hash' => $hash,
                'prepared_actor' => $actor->value,
                'prepared_by_name' => $actorName ?? $actor->label(),
                'prepared_at' => now(),
            ];

            if ($report === null) {
                $report = TaxReport::create($attributes + [
                    'type' => $type,
                    'period_start' => $period->start->toDateString(),
                    'status' => TaxReportStatus::Prepared,
                ]);

                $this->audit->record('tax_report.prepared', ['report' => $report->id, 'type' => $type->value, 'period' => $period->label(), 'payable_minor' => $computation->payableMinor], $actor, null, $actorName);

                return $report;
            }

            $changed = ! hash_equals($report->version_hash, $hash);
            $report->fill($attributes);

            // Any change to the figures voids an approval given for the previous version.
            if ($changed && ($report->approved_version_hash !== null || $report->status !== TaxReportStatus::Prepared)) {
                $report->fill([
                    'status' => TaxReportStatus::Prepared,
                    'approval_source' => null,
                    'approved_by' => null,
                    'approved_by_name' => null,
                    'mandate_id' => null,
                    'approved_version_hash' => null,
                    'approved_at' => null,
                    'scheduled_at' => null,
                    'requested_at' => null,
                    'requested_actor' => null,
                    'status_note' => __('tax.notes.approval_voided'),
                ]);
                ReportApproval::invalidateFor($report, __('tax.notes.approval_voided'));
                $this->audit->record('tax_report.approval_voided', ['report' => $report->id], $actor, __('tax.notes.approval_voided'), $actorName);
            }

            $report->save();

            if ($changed) {
                $this->audit->record('tax_report.recalculated', ['report' => $report->id, 'payable_minor' => $computation->payableMinor], $actor, null, $actorName);
            }

            return $report;
        });
    }

    /** Recomputes a report's figures without saving, for the "totals match" gate. */
    public function recompute(TaxReport $report): TaxComputation
    {
        if ($report->source === 'cabinet') {
            return CabinetReport::stored($report);
        }

        return $this->calculator->compute($report->type, $report->period(), $this->company->settings());
    }
}
