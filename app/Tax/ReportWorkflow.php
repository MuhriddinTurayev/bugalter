<?php

namespace App\Tax;

use App\Enums\Actor;
use App\Enums\Permission;
use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\PayrollRun;
use App\Models\ReportRun;
use App\Models\SigningKey;
use App\Models\TaxReport;
use App\Models\Transaction;
use App\Portal\PortalException;
use App\Portal\TaxPortal;
use App\Portal\TaxPortalCheck;
use App\Services\Audit;
use App\Services\CompanyNotifier;
use App\Services\CurrentCompany;
use App\Submission\Gateway\TaxAuthorityGateway;
use App\Submission\SubmissionRefused;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Hisobotlarni avtomatik tayyorlash": for a chosen month, go through the steps an accountant would and record
 * the real outcome of each one (done, skipped with the reason, warning, failed). Every figure comes from the
 * deterministic calculators; nothing is approved or submitted here. The review (rows()) is recomputed from the
 * current state of each report, so it never shows a stale status.
 *
 *   profile      the company's identity and regime as recorded in the settings
 *   portal       the tax cabinet visit (TaxPortalCheck), only with the owner's consent, a robot and a key
 *   obligations  which reports the regime owes for the month (the rest are "not required")
 *   data         what the books hold for those periods
 *   prepare      calculate and save each report (TaxReportPreparer)
 *   validate     data quality and the gateway's own pre-submission checks
 */
class ReportWorkflow
{
    public function __construct(
        private readonly CurrentCompany $company,
        private readonly TaxRuleBook $rules,
        private readonly TaxReportPreparer $preparer,
        private readonly TaxAuthorityGateway $gateway,
        private readonly TaxPortal $portal,
        private readonly Audit $audit,
        private readonly CompanyNotifier $notifier,
    ) {}

    /** Runs one queued run to the end. A second run of the same company waits for the first to finish. */
    public function run(ReportRun $run): ReportRun
    {
        $lock = Cache::lock('report-workflow', 60 * (ReportRun::STALE_MINUTES + 5));

        if (! $lock->get()) {
            $run->update(['status' => 'failed', 'error' => __('tax.workflow.errors.already_running'), 'finished_at' => now()]);

            return $run;
        }

        try {
            return $this->execute($run);
        } finally {
            $lock->release();
        }
    }

    private function execute(ReportRun $run): ReportRun
    {
        $this->company->forget();
        $actor = Actor::from($run->actor_type);
        $month = $run->month();
        $run->update(['status' => 'running', 'started_at' => now(), 'steps' => [], 'results' => null, 'error' => null]);

        try {
            $this->step($run, 'profile', fn () => $this->profile());
            $this->step($run, 'portal', fn () => $this->portal($actor, $run->actor_name));

            // The cabinet visit may have changed the settings (it never does today, but read them fresh).
            $this->company->forget();
            $settings = $this->company->settings();
            $rows = [];

            $this->step($run, 'obligations', function () use ($settings, $month, &$rows) {
                $rows = $this->obligations($settings, $month);

                return ['done', [
                    'required' => count(array_filter($rows, fn (array $r) => $r['applies'])),
                    'not_required' => count(array_filter($rows, fn (array $r) => ! $r['applies'])),
                ]];
            });

            $this->step($run, 'data', function () use (&$rows) {
                return $this->data($rows);
            });
            $this->step($run, 'prepare', function () use (&$rows, $actor, $run) {
                return $this->prepare($rows, $actor, $run->actor_name);
            });
            $this->step($run, 'validate', fn () => $this->validate($rows, $settings));
        } catch (Throwable $e) {
            report($e);
            $this->failRunningStep($run);
            $run->update(['status' => 'failed', 'error' => __('tax.workflow.errors.unexpected'), 'finished_at' => now(), 'results' => $rows ?? null]);
            $this->audit->record('tax_workflow.failed', ['run' => $run->id], $actor, null, $run->actor_name);

            return $run;
        }

        $run->update(['status' => 'completed', 'finished_at' => now(), 'results' => $rows]);

        $ready = count(array_filter($this->rows($run), fn (array $r) => $r['status'] === 'ready_for_approval'));
        $this->audit->record('tax_workflow.completed', ['run' => $run->id, 'month' => $month->format('Y-m'), 'reports' => count($rows), 'ready' => $ready], $actor, null, $run->actor_name);

        if ($ready > 0) {
            $this->notifier->withPermission(Permission::ReportsApprove, 'tax.notifications.workflow_ready', ['count' => $ready], route('tax.runs.show', $run, false));
        }

        return $run;
    }

    /**
     * The review: one row per report type, with its live status, figures, errors and warnings.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(ReportRun $run): array
    {
        $settings = $this->company->settings();
        $rows = [];

        foreach ($run->results ?? [] as $row) {
            $type = TaxReportType::from($row['type']);
            $report = $row['period_start'] === null ? null : TaxReport::query()
                ->where('type', $type->value)
                ->whereDate('period_start', $row['period_start'])
                ->first();

            $validatable = $report !== null && $row['closed'] && in_array($report->status, [TaxReportStatus::Prepared, TaxReportStatus::PendingApproval, TaxReportStatus::Blocked], true);
            $errors = $validatable ? $this->gateway->validate($report, $settings) : [];
            $status = ReportChecklist::status($report, $row['applies'], $row['closed'], $row['error'] !== null, $errors);

            $rows[] = $row + [
                'type_enum' => $type,
                'report' => $report,
                'status' => $status,
                'errors' => array_values(array_filter([...$errors, $row['error']])),
                'warnings' => $report === null ? [] : $this->warnings($report, $row),
                'selectable' => ReportChecklist::selectable($status),
            ];
        }

        return $rows;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function profile(): array
    {
        $settings = $this->company->settings();
        $params = [
            'name' => $settings->name,
            'tin' => (string) $settings->stir,
            'regime' => $settings->regime()->label(),
            'vat_payer' => (bool) $settings->vat_payer,
        ];

        return preg_match('/^\d{9}$/', (string) $settings->stir) ? ['done', $params] : ['warning', $params + ['problem' => 'no_tin']];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function portal(Actor $actor, ?string $actorName): array
    {
        $settings = $this->company->settings();

        $reason = match (true) {
            ! $settings->portal_access => 'no_access',
            $this->portal->configurationErrors() !== [] => 'not_configured',
            SigningKey::usable() === null => 'no_key',
            default => null,
        };

        if ($reason !== null) {
            return ['skipped', ['reason' => $reason]];
        }

        try {
            $check = app(TaxPortalCheck::class)->run($actor, $actorName);
        } catch (PortalException $e) {
            return ['failed', ['error' => $e->getMessage()]];
        }

        if ($check->status !== 'completed') {
            return ['failed', ['error' => (string) $check->error, 'check' => $check->id]];
        }

        if (isset($check->summary['tin_mismatch'])) {
            return ['failed', ['check' => $check->id, 'error' => __('tax.notifications.portal_tin_mismatch')]];
        }

        return ['done', [
            'check' => $check->id,
            'portal' => $this->portal->name(),
            'submitted' => (int) ($check->summary['submitted'] ?? 0),
            'missing' => (int) ($check->summary['missing'] ?? 0) + (int) ($check->summary['overdue'] ?? 0),
        ]];
    }

    /**
     * Every report type, with the period of its kind that contains the chosen month. Types the regime does not
     * owe are listed as not required instead of being dropped, so the review shows the whole picture.
     *
     * @return list<array<string, mixed>>
     */
    private function obligations(CompanySetting $settings, CarbonImmutable $month): array
    {
        $owed = $settings->regime()->reportTypes((bool) $settings->vat_payer);
        $rows = [];

        foreach (TaxReportType::cases() as $type) {
            $applies = in_array($type, $owed, true);
            $deadline = $this->rules->deadline($type, $month);

            if ($deadline === null) {
                $rows[] = ['type' => $type->value, 'applies' => $applies, 'period_kind' => null, 'period_start' => null, 'period_end' => null,
                    'due_date' => null, 'closed' => false, 'error' => $applies ? __('tax.errors.no_deadline') : null, 'source' => []];

                continue;
            }

            $period = TaxPeriod::containing($deadline->period, $month);
            $rows[] = [
                'type' => $type->value,
                'applies' => $applies,
                'period_kind' => $period->kind,
                'period_start' => $period->start->toDateString(),
                'period_end' => $period->end->toDateString(),
                'due_date' => $deadline->dueDateFor($period->end)->toDateString(),
                'closed' => $period->isClosed(),
                'error' => null,
                'source' => [],
            ];
        }

        return $rows;
    }

    /**
     * What the books hold for each owed period. An empty period is reported, never assumed to be a nil return.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function data(array &$rows): array
    {
        $empty = 0;

        foreach ($rows as $i => $row) {
            if (! $row['applies'] || $row['period_start'] === null) {
                continue;
            }

            $from = $row['period_start'];
            $to = $row['period_end'];

            $rows[$i]['source'] = [
                'documents' => Document::query()->whereBetween('issue_date', [$from, $to])->where('status', '!=', 'draft')->count(),
                'transactions' => Transaction::query()->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])->count(),
                'unclassified' => Transaction::query()->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])->whereNull('category_id')->count(),
                'payroll_runs' => PayrollRun::query()->whereBetween('period', [$from, $to])->count(),
            ];

            if ($rows[$i]['source']['documents'] === 0 && $rows[$i]['source']['transactions'] === 0) {
                $empty++;
            }
        }

        $owed = array_filter($rows, fn (array $r) => $r['applies'] && $r['period_start'] !== null);

        return [$empty > 0 ? 'warning' : 'done', [
            'documents' => array_sum(array_map(fn (array $r) => $r['source']['documents'], $owed)),
            'transactions' => array_sum(array_map(fn (array $r) => $r['source']['transactions'], $owed)),
            'empty_periods' => $empty,
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function prepare(array &$rows, Actor $actor, ?string $actorName): array
    {
        $prepared = $skipped = $failed = 0;

        foreach ($rows as $i => $row) {
            if (! $row['applies'] || $row['period_start'] === null || ! $row['closed']) {
                continue;
            }

            $type = TaxReportType::from($row['type']);
            $period = TaxPeriod::fromStart($row['period_kind'], CarbonImmutable::parse($row['period_start']));
            $existing = TaxReport::query()->where('type', $type->value)->whereDate('period_start', $period->start->toDateString())->first();

            // Never recalculate under an approval in progress or after the report left the application.
            if ($existing !== null && ($existing->status->isFinal() || in_array($existing->status, [TaxReportStatus::Scheduled, TaxReportStatus::AwaitingSignature], true))) {
                $rows[$i]['report_id'] = $existing->id;
                $skipped++;

                continue;
            }

            try {
                $rows[$i]['report_id'] = $this->preparer->prepare($type, $period, $actor, $actorName)->id;
                $prepared++;
            } catch (MissingTaxRule $e) {
                $rows[$i]['error'] = __('tax.errors.missing_rule', ['code' => $e->ruleCode]);
                $failed++;
            } catch (SubmissionRefused $e) {
                $rows[$i]['error'] = $e->getMessage();
                $failed++;
            }
        }

        return [$failed > 0 ? 'warning' : 'done', ['prepared' => $prepared, 'skipped' => $skipped, 'failed' => $failed]];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function validate(array $rows, CompanySetting $settings): array
    {
        $ready = $problems = 0;

        foreach ($rows as $row) {
            if (! isset($row['report_id'])) {
                continue;
            }

            $report = TaxReport::query()->find($row['report_id']);

            if ($report === null || ! in_array($report->status, [TaxReportStatus::Prepared, TaxReportStatus::PendingApproval, TaxReportStatus::Blocked], true)) {
                continue;
            }

            $status = ReportChecklist::status($report, true, $row['closed'], false, $this->gateway->validate($report, $settings));
            $status === 'ready_for_approval' ? $ready++ : $problems++;
        }

        return [$problems > 0 ? 'warning' : 'done', ['ready' => $ready, 'problems' => $problems]];
    }

    /** @return list<string> what a person should look at before approving; none of these block by itself */
    private function warnings(TaxReport $report, array $row): array
    {
        $warnings = [];

        foreach ($report->issues ?? [] as $issue) {
            if (($issue['severity'] ?? 'error') !== 'error') {
                $warnings[] = trans_choice('tax.issues.'.$issue['key'], $issue['count'] ?? 1, ($issue['params'] ?? []) + ['count' => $issue['count'] ?? 0]);
            }
        }

        if (($count = count($report->unverifiedRules())) > 0) {
            $warnings[] = __('tax.workflow.warnings.unverified_rules', ['count' => $count]);
        }

        if (($row['source']['documents'] ?? null) === 0 && ($row['source']['transactions'] ?? null) === 0) {
            $warnings[] = __('tax.workflow.warnings.no_source_data');
        }

        if ($report->isOverdue()) {
            $warnings[] = __('tax.workflow.warnings.overdue');
        }

        return $warnings;
    }

    /** @param  Closure(): array{0: string, 1: array<string, mixed>}  $work */
    private function step(ReportRun $run, string $key, Closure $work): void
    {
        $this->saveStep($run, $key, 'running', []);
        [$status, $params] = $work();
        $this->saveStep($run, $key, $status, $params);
    }

    private function failRunningStep(ReportRun $run): void
    {
        foreach ($run->steps ?? [] as $step) {
            if ($step['status'] === 'running') {
                $this->saveStep($run, $step['key'], 'failed', []);
            }
        }
    }

    private function saveStep(ReportRun $run, string $key, string $status, array $params): void
    {
        $steps = collect($run->steps ?? [])->keyBy('key');
        $steps[$key] = ['key' => $key, 'status' => $status, 'params' => $params, 'at' => now()->toIso8601String()];
        $run->update(['steps' => $steps->values()->all()]);
    }
}
