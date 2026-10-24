<?php

namespace App\Submission;

use App\Agent\AgentTasks;
use App\Enums\Actor;
use App\Enums\AutopilotLevel;
use App\Enums\Permission;
use App\Enums\TaxReportStatus;
use App\Models\CompanySetting;
use App\Models\Mandate;
use App\Models\ReportApproval;
use App\Models\SignatureLog;
use App\Models\SigningKey;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;
use App\Models\User;
use App\Services\Audit;
use App\Services\CompanyNotifier;
use App\Services\CurrentCompany;
use App\Submission\Gateway\DeliversThroughAgent;
use App\Submission\Gateway\GatewayResult;
use App\Submission\Gateway\SignsAuthorityDocument;
use App\Submission\Gateway\TaxAuthorityGateway;
use App\Submission\Signing\SignatureResult;
use App\Submission\Signing\Signer;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Decides whether a report may be submitted and carries the submission out (PLAN section 5). Deterministic:
 * the AI can only call requestSubmission(); approval needs a person with reports.approve or an active
 * mandate, every submission waits out the cancel window and passes the hard-stop gates before signing.
 */
class SubmissionOrchestrator
{
    public function __construct(
        private readonly CurrentCompany $company,
        private readonly SubmissionGates $gates,
        private readonly Signer $signer,
        private readonly TaxAuthorityGateway $gateway,
        private readonly Audit $audit,
        private readonly CompanyNotifier $notifier,
    ) {}

    /**
     * Step 2: ask for a submission. Creates an approval request; with autopilot level 3, an active mandate
     * and an autonomous actor, the mandate approves it and the cancel window starts. Never submits by itself.
     */
    public function requestSubmission(TaxReport $report, Actor $actor, ?string $actorName = null): TaxReport
    {
        $settings = $this->settings();

        if (in_array($actor, [Actor::Ai, Actor::Autopilot], true) && $settings->autopilot() === AutopilotLevel::Off) {
            throw new SubmissionRefused(__('tax.errors.autopilot_off'));
        }

        if (! in_array($report->status, [TaxReportStatus::Prepared, TaxReportStatus::Blocked, TaxReportStatus::Rejected], true)) {
            throw new SubmissionRefused(__('tax.errors.not_requestable', ['status' => $report->status->label()]));
        }

        if ($report->blockingIssues() !== []) {
            throw new SubmissionRefused(__('tax.errors.has_issues'));
        }

        $mandate = Mandate::activeFor($report);
        $autonomous = in_array($actor, [Actor::Ai, Actor::Autopilot], true);

        if ($autonomous && $settings->autopilot() === AutopilotLevel::Auto && $mandate !== null && ! $settings->isAutopilotPaused()) {
            return $this->approve($report, null, $mandate, $actor);
        }

        $report->update([
            'status' => TaxReportStatus::PendingApproval,
            'requested_at' => now(),
            'requested_actor' => $actor->value,
            'status_note' => null,
            'autopilot_hold' => $autonomous ? (bool) $report->autopilot_hold : false,
        ]);

        $this->audit->record('tax_report.submission_requested', ['report' => $report->id], $actor, null, $actorName);
        $this->notifier->withPermission(Permission::ReportsApprove, 'tax.notifications.approval_requested', ['report' => $report->title()], route('tax.reports.show', $report, false));

        return $report;
    }

    /**
     * Step 3: a person with reports.approve approves this exact version; the cancel window starts.
     *
     * @param  string|null  $expectedVersion  the version hash the person reviewed; refused when the report changed since
     * @param  array{batch?: string, declaration?: bool, ip?: ?string, user_agent?: ?string}  $meta
     */
    public function approveByUser(TaxReport $report, User $user, ?string $expectedVersion = null, array $meta = []): TaxReport
    {
        $this->assertApprovable($report, $user, false, $expectedVersion);

        return $this->approve($report, $user, null, Actor::User, meta: $meta);
    }

    /**
     * "Submit?" - "Yes": one click of a person who holds both reports.approve and reports.submit approves this
     * exact version and submits it at once. The person is the one who would cancel during the cancel window,
     * so there is none; every hard-stop gate still runs before anything is signed.
     */
    public function approveAndSubmit(TaxReport $report, User $user, ?string $expectedVersion = null, array $meta = []): TaxReport
    {
        $this->approveForImmediateSubmission($report, $user, $expectedVersion, $meta);

        return $this->submit($report, Actor::User, $user->name);
    }

    /**
     * Approves this exact version for submission without a cancel window, and leaves the sending to the caller
     * (approveAndSubmit, or the SubmitApprovedReport job of a batch approval). Needs reports.approve and
     * reports.submit. Nothing is signed or sent here.
     */
    public function approveForImmediateSubmission(TaxReport $report, User $user, ?string $expectedVersion = null, array $meta = []): TaxReport
    {
        $this->assertApprovable($report, $user, true, $expectedVersion);

        $this->approve($report, $user, null, Actor::User, immediate: true, meta: $meta);
        $this->audit->record('tax_report.submit_now', ['report' => $report->id, 'one_click' => true, 'batch' => $meta['batch'] ?? null]);

        return $report;
    }

    /** Throws SubmissionRefused unless this person may approve (and, with $andSubmit, submit) this exact version now. */
    public function assertApprovable(TaxReport $report, User $user, bool $andSubmit, ?string $expectedVersion): void
    {
        if (! $user->hasPermission(Permission::ReportsApprove) || ($andSubmit && ! $user->hasPermission(Permission::ReportsSubmit))) {
            throw new SubmissionRefused(__('tax.errors.no_permission'));
        }

        if (! in_array($report->status, [TaxReportStatus::Prepared, TaxReportStatus::PendingApproval, TaxReportStatus::Blocked, TaxReportStatus::Rejected], true)) {
            throw new SubmissionRefused(__('tax.errors.not_approvable', ['status' => $report->status->label()]));
        }

        if ($expectedVersion !== null && ! hash_equals($report->version_hash, $expectedVersion)) {
            throw new SubmissionRefused(__('tax.errors.version_changed', ['report' => $report->title()]));
        }

        if ($report->blockingIssues() !== []) {
            throw new SubmissionRefused(__('tax.errors.has_issues'));
        }

        if (SubmissionAttempt::openFor($report) !== null) {
            throw new SubmissionRefused(__('tax.errors.outcome_unknown'));
        }
    }

    /** Step 5: the owner (or anyone with reports.approve) cancels during the cancel window. */
    public function cancel(TaxReport $report, User $user): TaxReport
    {
        if (! $user->hasPermission(Permission::ReportsApprove)) {
            throw new SubmissionRefused(__('tax.errors.no_permission'));
        }

        if (! in_array($report->status, [TaxReportStatus::PendingApproval, TaxReportStatus::Scheduled, TaxReportStatus::AwaitingSignature, TaxReportStatus::Blocked], true)) {
            throw new SubmissionRefused(__('tax.errors.not_cancellable'));
        }

        $this->resetApproval($report, __('tax.notes.cancelled_by', ['name' => $user->name]));
        $this->audit->record('tax_report.cancelled', ['report' => $report->id]);

        return $report;
    }

    /** A person with reports.submit ends the cancel window early for a report a person approved. */
    public function submitNow(TaxReport $report, User $user): TaxReport
    {
        if (! $user->hasPermission(Permission::ReportsSubmit)) {
            throw new SubmissionRefused(__('tax.errors.no_permission'));
        }

        if ($report->status !== TaxReportStatus::Scheduled || $report->approval_source !== 'user') {
            throw new SubmissionRefused(__('tax.errors.not_submittable'));
        }

        $this->audit->record('tax_report.submit_now', ['report' => $report->id]);

        return $this->submit($report, Actor::User, $user->name);
    }

    /** Scheduler: submits every report whose cancel window is over. */
    public function processDue(): int
    {
        $count = 0;

        TaxReport::query()
            ->where('status', TaxReportStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get()
            ->each(function (TaxReport $report) use (&$count) {
                try {
                    $this->submit($report, $report->approval_source === 'mandate' ? Actor::Autopilot : Actor::User);
                    $count++;
                } catch (SubmissionRefused) {
                    // Already being submitted by someone else; that run finishes it.
                }
            });

        return $count;
    }

    /**
     * Steps 4 and 6: gates, then signing and sending. One submission per report at a time: a second click or
     * the scheduler meeting a report that is being sent is refused, and a report already sent is never resent.
     */
    public function submit(TaxReport $report, Actor $actor, ?string $actorName = null): TaxReport
    {
        $lock = Cache::lock('submission:'.$report->id, 900);

        if (! $lock->get()) {
            throw new SubmissionRefused(__('tax.errors.submission_in_progress'));
        }

        try {
            $report->refresh();

            // A delivery whose outcome is open (sending, submitted, unknown) is settled first, never repeated.
            if ($report->status === TaxReportStatus::Unknown || SubmissionAttempt::openFor($report) !== null) {
                throw new SubmissionRefused(__('tax.errors.outcome_unknown'));
            }

            if ($report->status->isFinal()) {
                throw new SubmissionRefused(__('tax.errors.already_submitted'));
            }

            return $this->submitLocked($report, $actor, $actorName);
        } finally {
            $lock->release();
        }
    }

    private function submitLocked(TaxReport $report, Actor $actor, ?string $actorName): TaxReport
    {
        $settings = $this->settings();
        $results = $this->gates->check($report, $settings);

        if (! SubmissionGates::passed($results)) {
            return $this->block($report, $results, $actor, $actorName);
        }

        // The desktop agent files it in the accountant's browser; E-IMZO signs there. Nothing is signed here.
        if ($this->gateway instanceof DeliversThroughAgent) {
            return app(AgentTasks::class)->dispatchSubmission($report, $results, $actor, $actorName);
        }

        if ($settings->signer_mode === 'device') {
            $report->update(['status' => TaxReportStatus::AwaitingSignature, 'gate_results' => $results, 'status_note' => null]);
            $this->audit->record('tax_report.awaiting_signature', ['report' => $report->id], $actor, null, $actorName);
            $this->notifier->owners('tax.notifications.sign_on_device', ['report' => $report->title()], route('tax.reports.show', $report, false));

            return $report;
        }

        if ($this->gateway instanceof SignsAuthorityDocument) {
            return $this->submitAuthorityDocument($this->gateway, $report, $settings, $results, $actor, $actorName);
        }

        try {
            $hash = ReportPayload::hash($report, $settings);
            $signature = $this->signer->sign($hash, SigningKey::usable() ?? throw new SubmissionRefused(__('autopilot.errors.key_unusable')));
        } catch (Throwable $e) {
            return $this->block($report, [...$results, ['key' => 'signing', 'passed' => false, 'params' => ['errors' => $e->getMessage()]]], $actor, $actorName);
        }

        return $this->send($report, $signature, $results, $actor, $actorName);
    }

    /** DeviceSigner: the owner signed the document hash with E-IMZO in their browser and posted the PKCS#7. */
    public function completeDeviceSignature(TaxReport $report, User $user, string $pkcs7): TaxReport
    {
        if (! $user->hasPermission(Permission::ReportsSubmit) && ! $user->hasPermission(Permission::ReportsApprove)) {
            throw new SubmissionRefused(__('tax.errors.no_permission'));
        }

        if ($report->status !== TaxReportStatus::AwaitingSignature) {
            throw new SubmissionRefused(__('tax.errors.not_awaiting_signature'));
        }

        $settings = $this->settings();
        $results = $this->gates->check($report, $settings, checkSigningKey: false);

        if (! SubmissionGates::passed($results)) {
            return $this->block($report, $results, Actor::User, $user->name);
        }

        $signature = new SignatureResult('device', ReportPayload::hash($report, $settings), trim($pkcs7), null, legallyValid: true);

        return $this->send($report, $signature, $results, Actor::User, $user->name);
    }

    /**
     * Step 7: the owner's kill switch. Autopilot goes to level 0 and every pending cancel window is cancelled.
     */
    public function killSwitch(User $user): int
    {
        if (! $user->hasPermission(Permission::AutopilotManage)) {
            throw new SubmissionRefused(__('tax.errors.no_permission'));
        }

        $settings = $this->settings();

        return DB::transaction(function () use ($settings, $user) {
            $settings->forceFill([
                'autopilot_level' => AutopilotLevel::Off,
                'autopilot_paused_at' => now(),
                'autopilot_paused_by' => $user->name,
            ])->save();

            $reports = TaxReport::query()->whereIn('status', [TaxReportStatus::Scheduled, TaxReportStatus::AwaitingSignature])->get();
            foreach ($reports as $report) {
                $this->resetApproval($report, __('tax.notes.kill_switch'));
            }

            $this->audit->record('autopilot.kill_switch', ['cancelled_reports' => $reports->count()]);

            return $reports->count();
        });
    }

    /**
     * $immediate: the approver submits right away (approveAndSubmit), so there is no cancel window to schedule.
     * The approval is bound to the version the caller checked: if the books changed the report meanwhile (the
     * preparer holds the same row lock), the approval is refused instead of covering figures nobody saw.
     */
    private function approve(TaxReport $report, ?User $user, ?Mandate $mandate, Actor $actor, bool $immediate = false, array $meta = []): TaxReport
    {
        $hours = max(0, (int) $this->settings()->cancel_window_hours);
        $checked = $report->version_hash;

        DB::transaction(function () use ($report, $user, $mandate, $actor, $immediate, $meta, $hours, $checked) {
            $current = TaxReport::query()->lockForUpdate()->findOrFail($report->id);

            if (! hash_equals($current->version_hash, $checked)) {
                throw new SubmissionRefused(__('tax.errors.version_changed', ['report' => $report->title()]));
            }

            $this->recordApproval($report, $user, $mandate, $actor, $immediate, $meta, $hours);
        });

        $this->audit->record(
            'tax_report.approved',
            ['report' => $report->id, 'version' => substr($report->version_hash, 0, 12), 'mandate' => $mandate?->id, 'scheduled_at' => $report->scheduled_at?->toIso8601String(), 'batch' => $meta['batch'] ?? null],
            $mandate ? Actor::Autopilot : Actor::User,
            $mandate ? __('tax.notes.approved_by_mandate', ['id' => $mandate->id]) : null,
        );

        if ($immediate) {
            return $report;
        }

        $this->notifier->owners('tax.notifications.cancel_window', [
            'report' => $report->title(),
            'time' => $report->scheduled_at->format('d.m.Y H:i'),
        ], route('tax.reports.show', $report, false));

        return $report;
    }

    private function recordApproval(TaxReport $report, ?User $user, ?Mandate $mandate, Actor $actor, bool $immediate, array $meta, int $hours): void
    {
        ReportApproval::invalidateFor($report, __('tax.notes.approval_replaced'));

        ReportApproval::create([
            'tax_report_id' => $report->id,
            'batch' => $meta['batch'] ?? null,
            'source' => $mandate ? 'mandate' : 'user',
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'mandate_id' => $mandate?->id,
            'version_hash' => $report->version_hash,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'payable_minor' => $report->payable_minor,
            'company_tin' => $this->settings()->stir,
            'declaration' => (bool) ($meta['declaration'] ?? false),
            'ip_address' => $meta['ip'] ?? null,
            'user_agent' => isset($meta['user_agent']) ? mb_substr((string) $meta['user_agent'], 0, 255) : null,
            'approved_at' => now(),
        ]);

        $report->update([
            'status' => TaxReportStatus::Scheduled,
            'approval_source' => $mandate ? 'mandate' : 'user',
            'approved_by' => $user?->id,
            'approved_by_name' => $user?->name,
            'mandate_id' => $mandate?->id,
            'approved_version_hash' => $report->version_hash,
            'approved_at' => now(),
            'requested_at' => $report->requested_at ?? now(),
            'requested_actor' => $report->requested_actor ?? $actor->value,
            // No time for an immediate submission: the hourly processDue() only picks up scheduled_at <= now.
            'scheduled_at' => $immediate ? null : now()->addHours($hours),
            'gate_results' => null,
            'status_note' => null,
            'autopilot_hold' => $mandate === null ? false : (bool) $report->autopilot_hold,
        ]);
    }

    /**
     * The authority builds the document to sign (the tax cabinet's form). It is signed only when it says exactly
     * what was approved; otherwise it is abandoned unsigned and the report is blocked.
     */
    private function submitAuthorityDocument(SignsAuthorityDocument $gateway, TaxReport $report, CompanySetting $settings, array $results, Actor $actor, ?string $actorName): TaxReport
    {
        $key = SigningKey::usable();

        if ($key === null) {
            return $this->block($report, [...$results, ['key' => 'signing_key', 'passed' => false]], $actor, $actorName);
        }

        try {
            $document = $gateway->prepareDocument($report, $settings, $actor, $actorName);
        } catch (Throwable $e) {
            return $this->block($report, [...$results, ['key' => 'authority_document', 'passed' => false, 'params' => ['errors' => $e->getMessage()]]], $actor, $actorName);
        }

        $mismatches = AuthorityDocumentCheck::mismatches($report, $settings, $document);

        if ($mismatches !== []) {
            $gateway->abandon($document);

            return $this->block($report, [...$results, ['key' => 'authority_document', 'passed' => false, 'params' => [
                'errors' => implode(', ', array_map(fn (string $field) => __('tax.document_fields.'.$field), $mismatches)),
            ]]], $actor, $actorName);
        }

        $results[] = ['key' => 'authority_document', 'passed' => true];

        try {
            $signature = $this->signer->signContent($document->content, $key, 'report');
        } catch (Throwable $e) {
            $gateway->abandon($document);

            return $this->block($report, [...$results, ['key' => 'signing', 'passed' => false, 'params' => ['errors' => $e->getMessage()]]], $actor, $actorName);
        }

        return $this->send($report, $signature, $results, $actor, $actorName, fn (string $key) => $gateway->deliver($report, $settings, $document, $signature, $key));
    }

    /**
     * Signs off and delivers once. The attempt row and the "submitting" status are written before anything
     * leaves the application, and anything short of a definite answer ends as "unknown", which blocks every
     * further submission of the report until SubmissionVerifier or a person settles it.
     *
     * @param  (Closure(string): GatewayResult)|null  $deliver  how the signed report leaves, given the idempotency key;
     *                                                          the gateway's submit() by default
     */
    private function send(TaxReport $report, SignatureResult $signature, array $results, Actor $actor, ?string $actorName, ?Closure $deliver = null): TaxReport
    {
        $approvalRef = $report->approval_source === 'mandate' ? 'mandate:'.$report->mandate_id : 'user:'.$report->approved_by;

        $log = SignatureLog::create([
            'signing_key_id' => $signature->signingKeyId,
            'tax_report_id' => $report->id,
            'signer' => $signature->signer,
            'document_hash' => $signature->documentHash,
            'signature_hash' => hash('sha256', $signature->signature),
            'actor_type' => $actor->value,
            'actor_name' => $actorName ?? $actor->label(),
            'approval_ref' => $approvalRef,
            'mandate_id' => $report->mandate_id,
            'purpose' => 'report',
        ]);

        $key = SubmissionAttempt::keyFor($report);
        $attempt = SubmissionAttempt::create([
            'tax_report_id' => $report->id,
            'idempotency_key' => $key,
            'version_hash' => $report->version_hash,
            'document_hash' => $signature->documentHash,
            'gateway' => $this->gateway->name(),
            'status' => 'sending',
            'signature_log_id' => $log->id,
            'actor_type' => $actor->value,
            'actor_name' => $actorName ?? $actor->label(),
            'approval_ref' => $approvalRef,
            'started_at' => now(),
        ]);

        $report->update([
            'status' => TaxReportStatus::Submitting,
            'gate_results' => $results,
            'signature_log_id' => $log->id,
            'submitted_at' => now(),
            'status_note' => null,
        ]);

        try {
            $result = $deliver !== null ? $deliver($key) : $this->gateway->submit($report, $this->settings(), $signature, $key);
        } catch (Throwable $e) {
            // The signed report may already be with the authority. Only the exception type is kept.
            report($e);
            $result = new GatewayResult('unknown', errors: [__('tax.errors.delivery_exception')], raw: ['error' => class_basename($e)]);
        }

        if (! in_array($result->status, ['accepted', 'rejected', 'submitted', 'unknown'], true)) {
            $result = new GatewayResult('unknown', $result->receipt, $result->errors, $result->raw);
        }

        return $this->recordOutcome($report, $attempt, $result, $actor, $actorName, ['legal_signature' => $signature->legallyValid]);
    }

    /**
     * Writes the authority's answer to the attempt and the report (after delivery, or later by SubmissionVerifier).
     * Accepted shows green only here, from the authority's own answer. "not_found" is not an outcome to record
     * here: SubmissionVerifier handles it.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordOutcome(TaxReport $report, SubmissionAttempt $attempt, GatewayResult $result, Actor $actor, ?string $actorName = null, array $context = [], ?string $verifiedBy = null): TaxReport
    {
        $status = match ($result->status) {
            'accepted' => TaxReportStatus::Accepted,
            'rejected' => TaxReportStatus::Rejected,
            'submitted' => TaxReportStatus::Submitted,
            default => TaxReportStatus::Unknown,
        };

        $attempt->update(array_filter([
            'status' => $result->status === 'not_found' ? 'unknown' : $result->status,
            'receipt_number' => $result->receipt ?? $attempt->receipt_number,
            'errors' => $result->errors === [] ? $attempt->errors : $result->errors,
            'response' => $result->raw === [] ? $attempt->response : $result->raw,
            'finished_at' => $attempt->finished_at ?? now(),
            'last_checked_at' => $verifiedBy !== null ? now() : null,
            'verified_at' => $verifiedBy !== null && $status !== TaxReportStatus::Unknown ? now() : null,
            'verified_by' => $verifiedBy !== null && $status !== TaxReportStatus::Unknown ? $verifiedBy : null,
        ], fn ($value) => $value !== null));

        $report->update([
            'status' => $status,
            'receipt_number' => $result->receipt ?? $report->receipt_number,
            'authority_response' => ['gateway' => $attempt->gateway, 'errors' => $result->errors] + $context + $result->raw,
            'status_note' => $result->errors === [] ? null : implode('; ', $result->errors),
        ]);

        $this->audit->record('tax_report.'.$status->value, [
            'report' => $report->id,
            'attempt' => $attempt->id,
            'receipt' => $result->receipt,
            'gateway' => $attempt->gateway,
            'signature_log' => $attempt->signature_log_id,
            'verified_by' => $verifiedBy,
        ], $actor, null, $actorName);

        $this->notifier->owners('tax.notifications.'.$status->value, ['report' => $report->title(), 'receipt' => (string) ($result->receipt ?? $report->receipt_number)], route('tax.reports.show', $report, false));

        return $report;
    }

    /** A hard-stop gate failed: the report stops, keeps its figures and the owner is told. Nothing was signed. */
    public function block(TaxReport $report, array $results, Actor $actor, ?string $actorName): TaxReport
    {
        $failed = collect($results)->where('passed', false)->pluck('key')->all();

        $report->update([
            'status' => TaxReportStatus::Blocked,
            'gate_results' => $results,
            'status_note' => implode(', ', array_map(fn (string $key) => __('tax.gates.'.$key), $failed)),
        ]);

        $this->audit->record('tax_report.blocked', ['report' => $report->id, 'failed_gates' => $failed], $actor, null, $actorName);
        $this->notifier->owners('tax.notifications.blocked', ['report' => $report->title()], route('tax.reports.show', $report, false));

        return $report;
    }

    /**
     * Back to "prepared" with no approval: a person cancelled ($hold: the autopilot must not ask again by itself),
     * or SubmissionVerifier found the authority never received the report (a fresh approval is needed to resend).
     */
    public function resetApproval(TaxReport $report, string $note, bool $hold = true): void
    {
        ReportApproval::invalidateFor($report, $note);

        $report->update([
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
            'status_note' => $note,
            'autopilot_hold' => $hold,
        ]);
    }

    private function settings(): CompanySetting
    {
        $this->company->forget();

        return $this->company->settings();
    }
}
