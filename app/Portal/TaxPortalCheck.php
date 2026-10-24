<?php

namespace App\Portal;

use App\Enums\Actor;
use App\Enums\Permission;
use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Models\CabinetPayment;
use App\Models\Document;
use App\Models\PayrollRun;
use App\Models\PortalCheck;
use App\Models\TaxReport;
use App\Models\Transaction;
use App\Services\Audit;
use App\Services\CompanyNotifier;
use App\Services\CurrentCompany;
use App\Submission\SubmissionOrchestrator;
use App\Submission\SubmissionRefused;
use App\Tax\MissingTaxRule;
use App\Tax\TaxObligations;
use App\Tax\TaxPeriod;
use App\Tax\TaxReportPreparer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Go into the tax cabinet and see what is there": sign in with the vault key, read the cabinet, compare it
 * with the books (PortalAnalyzer), prepare every report that is missing, overdue or rejected with the
 * deterministic calculators, and ask for approval. Nothing is submitted here; submission goes only through
 * SubmissionOrchestrator after a person's approval or under the owner's mandate.
 */
class TaxPortalCheck
{
    public function __construct(
        private readonly TaxPortal $portal,
        private readonly PortalSessions $sessions,
        private readonly PortalAnalyzer $analyzer,
        private readonly TaxObligations $obligations,
        private readonly TaxReportPreparer $preparer,
        private readonly SubmissionOrchestrator $orchestrator,
        private readonly CurrentCompany $company,
        private readonly Audit $audit,
        private readonly CompanyNotifier $notifier,
    ) {}

    /** @throws PortalException when another visit of this company's cabinet is still running */
    public function run(Actor $actor, ?string $actorName = null, ?CarbonImmutable $today = null): PortalCheck
    {
        $lock = Cache::lock('portal-check', 900);

        if (! $lock->get()) {
            throw new PortalException(__('tax.portal.errors.already_running'));
        }

        try {
            return $this->visit($actor, $actorName, $today ?? CarbonImmutable::today());
        } finally {
            $lock->release();
        }
    }

    private function visit(Actor $actor, ?string $actorName, CarbonImmutable $today): PortalCheck
    {
        $check = $this->begin($this->portal->name(), $actor, $actorName);

        try {
            $raw = $this->sessions->within($actor, $actorName, fn (string $session) => $this->portal->snapshot($session));
        } catch (Throwable $e) {
            if (! $e instanceof PortalException) {
                report($e);
            }

            return $this->fail($check, $e instanceof PortalException ? $e->getMessage() : __('tax.portal.errors.unexpected'));
        }

        return $this->complete($check, $raw, $today);
    }

    /** A visit that starts now and ends later: the desktop agent reads the cabinet in the accountant's browser. */
    public function begin(string $portal, Actor $actor, ?string $actorName = null): PortalCheck
    {
        return PortalCheck::create([
            'status' => 'running',
            'portal' => $portal,
            'actor_type' => $actor->value,
            'actor_name' => $actorName ?? $actor->label(),
            'started_at' => now(),
        ]);
    }

    /**
     * What the cabinet showed, from whichever reader: normalised (portal text stays data), compared with the books,
     * missing reports prepared and offered for approval. Nothing is submitted here.
     *
     * @param  array<string, mixed>  $raw
     */
    public function complete(PortalCheck $check, array $raw, ?CarbonImmutable $today = null): PortalCheck
    {
        $today ??= CarbonImmutable::today();
        $actor = Actor::from($check->actor_type);
        $actorName = $check->actor_name;
        $this->company->forget();
        $settings = $this->company->settings();

        try {
            $snapshot = PortalSnapshot::normalize($raw);
            $analysis = $this->analyzer->analyze($snapshot, $settings, $this->obligations->calendar($settings, 3, $today), $today);

            $prepared = isset($analysis['summary']['tin_mismatch']) ? ['prepared' => 0, 'requested' => 0, 'errors' => 0, 'findings' => $analysis['findings']]
                : $this->prepareMissing($analysis['findings'], $actor, $actorName);

            $this->storePayments($snapshot['payments']);

            // What the cabinet itself calculated for a period nobody has filed yet: a report to check and approve.
            if (! isset($analysis['summary']['tin_mismatch'])) {
                $fromCabinet = $this->prepareFromCabinet($snapshot, $actor, $actorName);
                $prepared = [
                    'prepared' => $prepared['prepared'] + $fromCabinet['prepared'],
                    'requested' => $prepared['requested'] + $fromCabinet['requested'],
                    'errors' => $prepared['errors'] + $fromCabinet['errors'],
                    'findings' => [...$fromCabinet['findings'], ...$prepared['findings']],
                ];
            }

            $check->update([
                'status' => 'completed',
                'finished_at' => now(),
                'snapshot' => $snapshot,
                'findings' => $prepared['findings'],
                'summary' => $analysis['summary'] + ['prepared' => $prepared['prepared'], 'requested' => $prepared['requested'], 'errors' => $prepared['errors']],
            ]);
        } catch (Throwable $e) {
            if (! $e instanceof PortalException) {
                report($e);
            }

            return $this->fail($check, $e instanceof PortalException ? $e->getMessage() : __('tax.portal.errors.unexpected'));
        }

        $this->audit->record('tax_portal.checked', ['check' => $check->id, 'portal' => $check->portal] + $check->summary, $actor, null, $actorName);

        if ($check->summary['requested'] > 0) {
            $this->notifier->withPermission(Permission::ReportsApprove, 'tax.notifications.portal_ready', ['count' => $check->summary['requested']], route('tax.portal', absolute: false));
        } elseif (isset($check->summary['tin_mismatch'])) {
            $this->notifier->owners('tax.notifications.portal_tin_mismatch', [], route('tax.portal', absolute: false));
        }

        return $check;
    }

    /**
     * The payment orders the cabinet lists, kept as it shows them (one row per number, date and tax code; a later read
     * only refreshes the status). A row without a number, date or tax code is not stored.
     *
     * @param  list<array<string, mixed>>  $payments
     */
    private function storePayments(array $payments): void
    {
        foreach ($payments as $p) {
            if ($p['number'] === null || $p['date'] === null || $p['tax_code'] === null) {
                continue;
            }

            $row = CabinetPayment::query()->where('order_number', $p['number'])->whereDate('order_date', $p['date'])->where('tax_code', $p['tax_code'])->first()
                ?? new CabinetPayment(['order_number' => $p['number'], 'order_date' => $p['date'], 'tax_code' => $p['tax_code']]);
            $row->fill([
                'tax' => $p['tax'], 'amount_minor' => $p['amount_minor'], 'status_text' => $p['status_text'], 'is_paid' => $p['paid'],
                'payer_bank_code' => $p['payer_bank_code'], 'payer_account' => $p['payer_account'], 'receiver_account' => $p['receiver_account'],
                'year' => $p['year'], 'first_seen_at' => $row->first_seen_at ?? now(), 'last_seen_at' => now(),
            ])->save();
        }
    }

    /**
     * A report whose figures the cabinet calculated: prepared from those figures (never from the books) and offered for a
     * person's approval. A period the cabinet already shows as filed, or this application already sent, is left alone.
     *
     * @param  array<string, mixed>  $snapshot  PortalSnapshot::normalize()
     * @return array{prepared: int, requested: int, errors: int, findings: list<array<string, mixed>>}
     */
    private function prepareFromCabinet(array $snapshot, Actor $actor, ?string $actorName): array
    {
        $prepared = $requested = $errors = 0;
        $findings = [];

        foreach ($snapshot['drafts'] as $draft) {
            if ($draft['type'] === null || $draft['period_start'] === null) {
                continue;
            }

            $type = TaxReportType::from($draft['type']);
            $period = TaxPeriod::fromStart('month', CarbonImmutable::parse($draft['period_start']));

            $filed = collect($snapshot['reports'])->contains(fn (array $r) => $r['type'] === $type->value && $r['period_start'] === $period->start->toDateString() && in_array($r['status'], ['accepted', 'submitted'], true));
            $existing = TaxReport::query()->where('type', $type->value)->whereDate('period_start', $period->start->toDateString())->first();

            if ($filed || ($existing !== null && ($existing->status->isFinal() || in_array($existing->status, [TaxReportStatus::Submitting, TaxReportStatus::Scheduled, TaxReportStatus::AwaitingSignature], true)))) {
                continue;
            }

            $finding = ['key' => 'cabinet_ready', 'severity' => 'info', 'type' => $type->value, 'period_kind' => 'month', 'period_start' => $period->start->toDateString(), 'due_date' => $draft['due_date'], 'params' => []];

            try {
                $report = $this->preparer->prepareFromCabinet($type, $period, $draft, $actor, $actorName);
            } catch (SubmissionRefused $e) {
                $errors++;
                $findings[] = ['severity' => 'warning', 'params' => ['error' => $e->getMessage()]] + $finding;

                continue;
            }

            $prepared++;
            $finding['report_id'] = $report->id;
            $finding['params']['amount_minor'] = $report->payable_minor;

            if ($report->blockingIssues() === [] && ! ($report->autopilot_hold && $actor !== Actor::User)) {
                try {
                    $this->orchestrator->requestSubmission($report, $actor, $actorName);
                    $requested++;
                } catch (SubmissionRefused $e) {
                    $finding['params']['error'] = $e->getMessage();
                }
            }

            $findings[] = $finding;
        }

        return ['prepared' => $prepared, 'requested' => $requested, 'errors' => $errors, 'findings' => $findings];
    }

    /** Does the company have any bookkeeping for the period: a sent document, a money movement or a payroll run? */
    private function hasBooks(TaxPeriod $period): bool
    {
        $from = $period->start->toDateString();
        $to = $period->end->toDateString();

        return Document::query()->whereBetween('issue_date', [$from, $to])->where('status', '!=', 'draft')->exists()
            || Transaction::query()->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])->exists()
            || PayrollRun::query()->whereBetween('period', [$from, $to])->exists();
    }

    public function fail(PortalCheck $check, string $error): PortalCheck
    {
        $check->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($error, 0, 1000)]);

        $this->audit->record('tax_portal.failed', ['check' => $check->id, 'error' => $check->error], Actor::from($check->actor_type), null, $check->actor_name);
        $this->notifier->owners('tax.notifications.portal_failed', ['error' => $check->error], route('tax.portal', absolute: false));

        return $check;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array{prepared: int, requested: int, errors: int, findings: list<array<string, mixed>>}
     */
    private function prepareMissing(array $findings, Actor $actor, ?string $actorName): array
    {
        $prepared = $requested = $errors = 0;

        foreach ($findings as $i => $finding) {
            if (! in_array($finding['key'], ['missing', 'overdue', 'rejected'], true)) {
                continue;
            }

            $type = TaxReportType::from($finding['type']);
            $period = TaxPeriod::fromStart($finding['period_kind'], CarbonImmutable::parse($finding['period_start']));
            $existing = TaxReport::query()->where('type', $type->value)->whereDate('period_start', $period->start->toDateString())->first();

            // Never touch a report that is being submitted, or that this application already sent.
            if ($existing !== null && ($existing->status->isFinal() || in_array($existing->status, [TaxReportStatus::Scheduled, TaxReportStatus::AwaitingSignature, TaxReportStatus::PendingApproval], true))) {
                $findings[$i]['report_id'] = $existing->id;
                $findings[$i]['params']['local_status'] = $existing->status->value;

                continue;
            }

            // No document, no money movement and no payroll in that period: there is nothing true to put in a return,
            // and a "0 so'm" return is not invented. The finding stays; the report is not prepared.
            if (! $this->hasBooks($period)) {
                $findings[$i]['params']['error'] = __('tax.portal.errors.no_books');

                continue;
            }

            try {
                $report = $this->preparer->prepare($type, $period, $actor, $actorName);
                $prepared++;
                $findings[$i]['report_id'] = $report->id;
            } catch (MissingTaxRule|SubmissionRefused $e) {
                $errors++;
                $findings[$i]['params']['error'] = $e instanceof MissingTaxRule ? __('tax.errors.missing_rule', ['code' => $e->ruleCode]) : $e->getMessage();

                continue;
            }

            // A person who stopped this report keeps it stopped; the autopilot does not ask again by itself.
            if ($report->blockingIssues() !== [] || ($report->autopilot_hold && $actor !== Actor::User)) {
                continue;
            }

            try {
                $this->orchestrator->requestSubmission($report, $actor, $actorName);
                $requested++;
            } catch (SubmissionRefused $e) {
                $findings[$i]['params']['error'] = $e->getMessage();
            }
        }

        return ['prepared' => $prepared, 'requested' => $requested, 'errors' => $errors, 'findings' => $findings];
    }
}
