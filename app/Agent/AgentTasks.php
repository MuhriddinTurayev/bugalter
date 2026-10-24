<?php

namespace App\Agent;

use App\Enums\Actor;
use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Models\AgentTask;
use App\Models\CompanySetting;
use App\Models\DesktopAgent;
use App\Models\PortalCheck;
use App\Models\SignatureLog;
use App\Models\SubmissionAttempt;
use App\Models\TaxPayment;
use App\Models\TaxReport;
use App\Models\User;
use App\Payments\PaymentOrchestrator;
use App\Portal\AuthorityDocument;
use App\Portal\PortalSnapshot;
use App\Portal\TaxPortalCheck;
use App\Services\Audit;
use App\Services\CompanyNotifier;
use App\Services\CurrentCompany;
use App\Submission\AuthorityDocumentCheck;
use App\Submission\Gateway\GatewayResult;
use App\Submission\ReportPayload;
use App\Submission\SubmissionGates;
use App\Submission\SubmissionOrchestrator;
use App\Submission\SubmissionRefused;
use App\Submission\SubmissionVerifier;
use App\Support\Money;
use App\Tax\CabinetReport;
use App\Tax\TaxPeriod;
use App\Tax\TaxRuleBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only way work reaches the desktop agent and results come back. Laravel decides everything; the agent carries
 * it out in the accountant's browser and reports what it saw. What the agent sends is untrusted input:
 *
 *  - a cabinet snapshot goes through PortalSnapshot::normalize() and PortalAnalyzer like the robot's;
 *  - before it may sign a report, the agent sends the values it read back from the filled form. The hard-stop gates
 *    run again and the values must equal the approved report (AuthorityDocumentCheck). Only then is the attempt
 *    recorded and the agent told to go on;
 *  - after that point every failure, lost lease or missing answer is "unknown", never "not sent".
 */
class AgentTasks
{
    public const WAITING_FOR = ['eimzo_password', 'eimzo_confirmation', 'captcha', 'key_selection', 'login', 'browser', 'final_confirmation'];

    public function __construct(
        private readonly CurrentCompany $company,
        private readonly TaxPortalCheck $portalCheck,
        private readonly Audit $audit,
        private readonly CompanyNotifier $notifier,
    ) {}

    /** "Look at the cabinet": one open cabinet task at a time. */
    public function requestPortalCheck(Actor $actor, ?string $actorName = null): AgentTask
    {
        return DB::transaction(function () use ($actor, $actorName) {
            $open = AgentTask::query()->where('type', 'portal_check')->whereIn('status', AgentTask::OPEN)->lockForUpdate()->latest('id')->first();

            if ($open !== null) {
                return $open;
            }

            $check = $this->portalCheck->begin('desktop', $actor, $actorName);
            $task = AgentTask::create([
                'type' => 'portal_check',
                'status' => 'queued',
                'portal_check_id' => $check->id,
                'payload' => $this->companyPayload(),
                'actor_type' => $actor->value,
                'actor_name' => $actorName ?? $actor->label(),
            ]);

            $this->audit->record('agent_task.queued', ['task' => $task->id, 'type' => $task->type], $actor, null, $actorName);

            return $task;
        });
    }

    /**
     * "Show me what the cabinet calculated for this month, without sending anything": the agent opens the real form, chooses the
     * month and reads the figures. No report is created, nothing is approved, signed or sent.
     */
    public function requestRehearsal(int $year, int $month, Actor $actor, ?string $actorName = null): AgentTask
    {
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2100) {
            throw new SubmissionRefused(__('tax.agent.errors.protocol'));
        }

        if (AgentTask::query()->where('type', 'rehearsal')->whereIn('status', AgentTask::OPEN)->exists()) {
            throw new SubmissionRefused(__('tax.agent.errors.rehearsal_running'));
        }

        $task = AgentTask::create([
            'type' => 'rehearsal',
            'status' => 'queued',
            'payload' => $this->companyPayload() + ['month' => ['year' => $year, 'month' => $month]],
            'actor_type' => $actor->value,
            'actor_name' => $actorName ?? $actor->label(),
        ]);

        $this->audit->record('agent_task.queued', ['task' => $task->id, 'type' => $task->type, 'period' => sprintf('%04d-%02d', $year, $month)], $actor, null, $actorName);

        return $task;
    }

    /**
     * Called by SubmissionOrchestrator after every hard-stop gate passed: the approved version is frozen into the
     * task. Nothing is signed here; the report waits in "submitting" for the agent.
     *
     * @param  list<array<string, mixed>>  $gateResults
     */
    public function dispatchSubmission(TaxReport $report, array $gateResults, Actor $actor, ?string $actorName): TaxReport
    {
        if (AgentTask::query()->where('tax_report_id', $report->id)->whereIn('status', AgentTask::OPEN)->exists()) {
            throw new SubmissionRefused(__('tax.errors.submission_in_progress'));
        }

        $settings = $this->company->settings();
        $payload = ReportPayload::for($report, $settings);

        $task = AgentTask::create([
            'type' => 'submit_report',
            'status' => 'queued',
            'tax_report_id' => $report->id,
            'version_hash' => $report->version_hash,
            'payload' => $this->companyPayload() + [
                'title' => $report->title(),
                'report' => $payload,
                // What the filled form must say; the agent compares too, Laravel decides (formCheck()).
                'expected' => [
                    'tin' => (string) ($settings->stir ?: $settings->pinfl),
                    'type' => $report->type->value,
                    'period_start' => $report->period_start->toDateString(),
                    'period_end' => $report->period_end->toDateString(),
                    'base_minor' => $report->base_minor,
                    'tax_minor' => $report->tax_minor,
                    'payable_minor' => $report->payable_minor,
                ],
            ],
            'actor_type' => $actor->value,
            'actor_name' => $actorName ?? $actor->label(),
        ]);

        $report->update(['status' => TaxReportStatus::Submitting, 'gate_results' => $gateResults, 'status_note' => __('tax.agent.notes.queued')]);

        $this->audit->record('tax_report.sent_to_agent', ['report' => $report->id, 'task' => $task->id, 'version' => substr($report->version_hash, 0, 12)], $actor, null, $actorName);
        $this->notifier->owners('tax.agent.notifications.queued', ['report' => $report->title()], route('tax.agent', absolute: false));

        return $report;
    }

    /** Called by PaymentOrchestrator once a person approved a payment: the approved values are frozen into the task. */
    public function dispatchPayment(TaxPayment $payment, Actor $actor, ?string $actorName): AgentTask
    {
        $settings = $this->company->settings();

        $task = AgentTask::create([
            'type' => 'pay',
            'status' => 'queued',
            'tax_payment_id' => $payment->id,
            'version_hash' => $payment->version_hash,
            'payload' => $this->companyPayload() + [
                'payment' => [
                    'tax_code' => $payment->tax_code,
                    'kind' => $payment->kind,
                    'amount_minor' => $payment->amount_minor,
                    'purpose' => $payment->purpose,
                    'region' => (string) $settings->tax_region,
                    'district' => (string) $settings->tax_district,
                    'payer_account' => (string) $settings->bank_account,
                    'dry_run' => $payment->dry_run,
                ],
            ],
            'actor_type' => $actor->value,
            'actor_name' => $actorName ?? $actor->label(),
        ]);

        $this->notifier->owners('tax.payments.notifications.queued', ['amount' => Money::format($payment->amount_minor), 'tax' => $payment->tax_code], route('tax.payments', absolute: false));

        return $task;
    }

    /**
     * The last moment before the cabinet's own confirm button. The values the agent read from the filled cabinet form (and the
     * confirmation window) must be exactly what the person approved. Only then is the attempt recorded and the agent told to go on.
     *
     * @param  array<string, scalar|null>  $fields
     * @return array{proceed: bool, mismatches: list<string>, idempotency_key?: string, dry_run?: bool}
     */
    public function paymentCheck(AgentTask $task, array $fields): array
    {
        $this->assertClaimed($task);
        $payment = TaxPayment::query()->find($task->tax_payment_id);

        if ($task->type !== 'pay' || $payment === null || $payment->attempt_started_at !== null || $payment->status !== 'queued') {
            throw new SubmissionRefused(__('tax.agent.errors.protocol'));
        }

        if (! $this->paymentStillDeliverable($task)) {
            $this->failPayment($task, $payment, __('tax.payments.errors.changed'));

            return ['proceed' => false, 'mismatches' => ['version']];
        }

        $expected = ['tax_code' => $payment->tax_code, 'kind' => $payment->kind, 'amount_minor' => $payment->amount_minor];
        $mismatches = [];
        foreach ($expected as $key => $value) {
            $actual = $fields[$key] ?? null;
            $same = is_int($value) ? (is_int($actual) || (is_string($actual) && preg_match('/^\d+$/', $actual))) && (int) $actual === $value : (string) $actual === $value;
            if (! $same) {
                $mismatches[] = $key;
            }
        }

        if ($mismatches !== []) {
            $this->failPayment($task, $payment, __('tax.payments.errors.form_mismatch', ['fields' => implode(', ', $mismatches)]));

            return ['proceed' => false, 'mismatches' => $mismatches];
        }

        // A check without paying: the form matched, the agent cancels the cabinet's window, nothing is paid and nothing is retried.
        if ($payment->dry_run) {
            $payment->update(['status' => 'checked', 'error' => null, 'result' => ['checked' => $fields]]);
            $this->finishTask($task, 'completed', null, ['status' => 'checked']);
            $this->audit->record('tax_payment.checked', ['payment' => $payment->id], Actor::from($task->actor_type), null, $task->actor_name);

            return ['proceed' => false, 'mismatches' => [], 'dry_run' => true];
        }

        $payment->update(['status' => 'sending', 'attempt_started_at' => now(), 'error' => null]);
        $task->update(['heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes(AgentTask::LEASE_MINUTES)]);
        $this->audit->record('tax_payment.form_checked', ['payment' => $payment->id, 'task' => $task->id], Actor::from($task->actor_type), null, $task->actor_name);

        return ['proceed' => true, 'mismatches' => [], 'idempotency_key' => $payment->idempotency_key];
    }

    /** A person takes back a payment the agent has not reached the cabinet's confirm button for. */
    public function cancelPayment(TaxPayment $payment, User $user): void
    {
        $task = AgentTask::query()->where('tax_payment_id', $payment->id)->whereIn('status', AgentTask::OPEN)->latest('id')->first();
        $note = __('tax.notes.cancelled_by', ['name' => $user->name]);

        if ($task !== null) {
            $this->finishTask($task, 'cancelled', $note);
        }

        $payment->update(['status' => 'cancelled', 'error' => $note]);
        $this->audit->record('tax_payment.cancelled', ['payment' => $payment->id], Actor::User, null, $user->name);
    }

    private function failPayment(AgentTask $task, TaxPayment $payment, string $error): void
    {
        $this->finishTask($task, 'failed', $error);
        $payment->update(['status' => 'failed', 'error' => $error]);
        $this->audit->record('tax_payment.failed', ['payment' => $payment->id, 'error' => $error], Actor::System);
    }

    private function paymentStillDeliverable(AgentTask $task): bool
    {
        $payment = TaxPayment::query()->find($task->tax_payment_id);

        return $payment !== null && $payment->status === 'queued' && $task->version_hash !== null && hash_equals($payment->version_hash, $task->version_hash);
    }

    /** What the cabinet's list showed after the payment: "paid", "submitted" (an order exists, not yet paid) or unknown. */
    private function completePayment(AgentTask $task, array $result): AgentTask
    {
        $payment = TaxPayment::query()->findOrFail($task->tax_payment_id);

        if ($payment->attempt_started_at === null) {
            // The agent reported an outcome without the check: the protocol was broken, something may have been paid.
            $payment->update(['attempt_started_at' => now()]);
            $status = 'unknown';
            $result['errors'] = [__('tax.agent.errors.protocol')];
        } else {
            $status = in_array($result['status'] ?? null, ['paid', 'submitted', 'failed'], true) ? $result['status'] : 'unknown';
        }

        $order = is_string($result['order_number'] ?? null) && preg_match('/^\d{1,12}$/', $result['order_number']) ? $result['order_number'] : null;
        $errors = array_values(array_map(fn ($e) => mb_substr((string) $e, 0, 300), array_filter((array) ($result['errors'] ?? []), 'is_scalar')));

        $payment->update([
            'status' => $status,
            'order_number' => $order,
            'order_date' => $order !== null ? now()->toDateString() : null,
            'result' => ['errors' => $errors],
            'error' => $errors === [] ? null : implode(' | ', $errors),
        ]);
        $this->audit->record('tax_payment.'.$status, ['payment' => $payment->id, 'order' => $order], Actor::from($task->actor_type), null, $task->actor_name);

        return $this->finishTask($task, 'completed', null, ['status' => $status, 'order' => $order]);
    }

    /** The next task for this agent, leased to it. A submission whose report changed meanwhile is dropped. */
    public function claim(DesktopAgent $agent): ?AgentTask
    {
        $this->expireLeases();

        return DB::transaction(function () use ($agent) {
            $task = AgentTask::query()->where('status', 'queued')->orderBy('id')->lockForUpdate()->first();

            if ($task === null) {
                return null;
            }

            if ($task->type === 'pay' && ! $this->paymentStillDeliverable($task)) {
                $task->update(['status' => 'cancelled', 'error' => __('tax.payments.errors.changed'), 'finished_at' => now()]);

                return null;
            }

            if ($task->type === 'submit_report' && ! $this->stillDeliverable($task)) {
                $task->update(['status' => 'cancelled', 'error' => __('tax.agent.errors.report_changed'), 'finished_at' => now()]);

                return null;
            }

            $task->update([
                'status' => 'claimed',
                'desktop_agent_id' => $agent->id,
                'claimed_at' => now(),
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addMinutes(AgentTask::LEASE_MINUTES),
            ]);

            $this->audit->record('agent_task.claimed', ['task' => $task->id, 'agent' => $agent->id], Actor::System, null, $agent->name);

            return $task;
        });
    }

    /** Progress, or "the accountant must act" (type the E-IMZO password, solve a CAPTCHA, ...). Extends the lease. */
    public function event(AgentTask $task, string $kind, string $message, ?string $waitingFor = null): AgentTask
    {
        $this->assertClaimed($task);

        $needsUser = $kind === 'needs_user';
        $events = [...($task->events ?? []), [
            'at' => now()->toIso8601String(),
            'kind' => $kind,
            'message' => mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '', 0, 300),
            'waiting_for' => $needsUser ? $waitingFor : null,
        ]];

        $wasWaiting = $task->status === 'needs_user' && $task->waiting_for === $waitingFor;

        $task->update([
            'status' => $needsUser ? 'needs_user' : 'claimed',
            'waiting_for' => $needsUser ? $waitingFor : null,
            'events' => array_slice($events, -AgentTask::MAX_EVENTS),
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addMinutes(AgentTask::LEASE_MINUTES),
        ]);

        if ($needsUser && ! $wasWaiting) {
            $this->notifier->owners('tax.agent.notifications.needs_user', [
                'what' => __('tax.agent.waiting_for.'.$waitingFor),
                'computer' => (string) $task->agent?->name,
            ], route('tax.agent', absolute: false));
        }

        return $task;
    }

    /**
     * The last moment before signing. The gates run again and the values the agent read back from the filled form
     * must be the approved figures. Only on success is the attempt recorded (so from here on the outcome is
     * tracked and never repeated blindly) and the agent told to sign and send.
     *
     * @param  array<string, scalar|null>  $fields
     * @return array{proceed: bool, mismatches: list<string>, idempotency_key?: string}
     */
    public function formCheck(AgentTask $task, array $fields): array
    {
        $this->assertClaimed($task);

        if ($task->type !== 'submit_report' || $task->submission_attempt_id !== null) {
            throw new SubmissionRefused(__('tax.agent.errors.protocol'));
        }

        // Nothing is signed unless the accountant answered "send" for the filled form in the panel.
        if (($task->result['decision'] ?? null) !== 'send') {
            throw new SubmissionRefused(__('tax.agent.errors.protocol'));
        }

        $report = TaxReport::query()->findOrFail($task->tax_report_id);
        $settings = $this->settings();

        if (! $this->stillDeliverable($task)) {
            $this->failBeforeSigning($task, $report, __('tax.agent.errors.report_changed'));

            return ['proceed' => false, 'mismatches' => ['version']];
        }

        $results = app(SubmissionGates::class)->check($report, $settings, checkSigningKey: false);

        if (! SubmissionGates::passed($results)) {
            $this->finishTask($task, 'failed', __('tax.agent.errors.gates_failed'));
            app(SubmissionOrchestrator::class)->block($report, $results, Actor::from($task->actor_type), $task->actor_name);

            return ['proceed' => false, 'mismatches' => collect($results)->where('passed', false)->pluck('key')->values()->all()];
        }

        $document = new AuthorityDocument('desktop', 'task-'.$task->id, json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $fields);
        $mismatches = AuthorityDocumentCheck::mismatches($report, $settings, $document);

        if ($mismatches !== []) {
            $this->finishTask($task, 'failed', __('tax.agent.errors.form_mismatch'));
            app(SubmissionOrchestrator::class)->block($report, [...$results, ['key' => 'authority_document', 'passed' => false, 'params' => [
                'errors' => implode(', ', array_map(fn (string $field) => __('tax.document_fields.'.$field), $mismatches)),
            ]]], Actor::from($task->actor_type), $task->actor_name);

            return ['proceed' => false, 'mismatches' => $mismatches];
        }

        $attempt = SubmissionAttempt::create([
            'tax_report_id' => $report->id,
            'idempotency_key' => SubmissionAttempt::keyFor($report),
            'version_hash' => $report->version_hash,
            'document_hash' => $document->hash(),
            'gateway' => 'desktop',
            'status' => 'sending',
            'actor_type' => $task->actor_type,
            'actor_name' => $task->actor_name,
            'approval_ref' => $report->approval_source === 'mandate' ? 'mandate:'.$report->mandate_id : 'user:'.$report->approved_by,
            'started_at' => now(),
        ]);

        $task->update(['submission_attempt_id' => $attempt->id, 'heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes(AgentTask::LEASE_MINUTES)]);
        $report->update(['gate_results' => [...$results, ['key' => 'authority_document', 'passed' => true]], 'status_note' => null]);
        $this->audit->record('tax_report.form_checked', ['report' => $report->id, 'task' => $task->id, 'attempt' => $attempt->id], Actor::from($task->actor_type), null, $task->actor_name);

        return ['proceed' => true, 'mismatches' => [], 'idempotency_key' => $attempt->idempotency_key];
    }

    /**
     * The form is filled in the real cabinet and nothing has been signed or sent. The values the agent read back are
     * shown to the accountant, who answers "send" or "do not send" in the panel. Laravel compares them with the
     * approved report here too, so a form that differs can be seen (and cannot be confirmed).
     *
     * @param  array<string, scalar|null>  $fields
     * @return array{mismatches: list<string>}
     */
    public function previewForConfirmation(AgentTask $task, array $fields): array
    {
        $this->assertClaimed($task);

        if ($task->type !== 'submit_report' || $task->submission_attempt_id !== null) {
            throw new SubmissionRefused(__('tax.agent.errors.protocol'));
        }

        $report = TaxReport::query()->findOrFail($task->tax_report_id);

        if (! $this->stillDeliverable($task)) {
            $this->failBeforeSigning($task, $report, __('tax.agent.errors.report_changed'));

            throw new SubmissionRefused(__('tax.agent.errors.report_changed'));
        }

        $document = new AuthorityDocument('desktop', 'task-'.$task->id, json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $fields);
        $mismatches = AuthorityDocumentCheck::mismatches($report, $this->settings(), $document);

        $task->update(['result' => ['preview' => $fields, 'mismatches' => $mismatches, 'decision' => null]]);
        $this->event($task, 'needs_user', __('tax.agent.final_asked'), 'final_confirmation');

        return ['mismatches' => $mismatches];
    }

    /** The agent asks whether the accountant has answered. Asking also keeps the task alive while a person decides. */
    public function finalDecision(AgentTask $task): ?string
    {
        $this->assertClaimed($task);
        $task->update(['heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes(AgentTask::LEASE_MINUTES)]);

        return $task->result['decision'] ?? null;
    }

    /** "Yes, send it": allowed only while the task waits for exactly this and the filled form matches the approved report. */
    public function confirmFinal(AgentTask $task, User $user): AgentTask
    {
        $this->assertWaitingForFinal($task);

        if (($task->result['mismatches'] ?? []) !== [] || ! $this->stillDeliverable($task)) {
            throw new SubmissionRefused(__('tax.agent.errors.form_mismatch'));
        }

        $task->update(['status' => 'claimed', 'waiting_for' => null, 'result' => [...$task->result, 'decision' => 'send']]);
        $this->audit->record('tax_report.final_confirmed', ['report' => $task->tax_report_id, 'task' => $task->id, 'version' => substr((string) $task->version_hash, 0, 12)], Actor::User, null, $user->name);

        return $task;
    }

    /** "No, do not send": the task ends, nothing was signed, the approval is withdrawn and the report is "prepared" again. */
    public function declineFinal(AgentTask $task, User $user): AgentTask
    {
        $this->assertWaitingForFinal($task);

        $note = __('tax.agent.final_declined_by', ['name' => $user->name]);
        $this->finishTask($task, 'cancelled', $note);

        if (($report = TaxReport::query()->find($task->tax_report_id)) !== null && $report->status === TaxReportStatus::Submitting) {
            app(SubmissionOrchestrator::class)->resetApproval($report, $note);
        }

        $this->audit->record('tax_report.final_declined', ['report' => $task->tax_report_id, 'task' => $task->id], Actor::User, null, $user->name);

        return $task;
    }

    private function assertWaitingForFinal(AgentTask $task): void
    {
        if ($task->status !== 'needs_user' || $task->waiting_for !== 'final_confirmation' || $task->submission_attempt_id !== null || ! isset($task->result['preview'])) {
            throw new SubmissionRefused(__('tax.agent.errors.not_cancellable'));
        }
    }

    /** @param  array<string, mixed>  $result */
    public function complete(AgentTask $task, array $result): AgentTask
    {
        $this->assertClaimed($task);

        if ($task->type === 'rehearsal') {
            return $this->completeRehearsal($task, $result);
        }

        if ($task->type === 'pay') {
            return $this->completePayment($task, $result);
        }

        if ($task->type === 'portal_check') {
            $check = PortalCheck::query()->findOrFail($task->portal_check_id);
            $this->linkOnFirstUse((string) ($result['snapshot']['taxpayer']['tin'] ?? ''), $task);
            $this->portalCheck->complete($check, (array) ($result['snapshot'] ?? []));
            app(PaymentOrchestrator::class)->reconcile();
            $this->finishTask($task, $check->status === 'completed' ? 'completed' : 'failed', $check->error, ['portal_check' => $check->id]);

            try {
                app(SubmissionVerifier::class)->verifyPending();
            } catch (Throwable $e) {
                report($e);
            }

            return $task;
        }

        $report = TaxReport::query()->findOrFail($task->tax_report_id);
        $attempt = $task->submission_attempt_id ? SubmissionAttempt::query()->find($task->submission_attempt_id) : null;
        $status = in_array($result['status'] ?? null, ['accepted', 'rejected', 'submitted'], true) ? $result['status'] : 'unknown';

        if ($attempt === null) {
            // A result without the form check: the agent broke the protocol. Something may have been sent.
            $attempt = $this->attemptWithoutCheck($task, $report);
            $status = 'unknown';
            $result['errors'] = [__('tax.agent.errors.protocol')];
        }

        if (is_string($result['pkcs7_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $result['pkcs7_sha256'])) {
            $log = SignatureLog::create([
                'signing_key_id' => null,
                'tax_report_id' => $report->id,
                'signer' => 'eimzo',
                'document_hash' => is_string($result['content_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $result['content_sha256']) ? $result['content_sha256'] : (string) $attempt->document_hash,
                'signature_hash' => $result['pkcs7_sha256'],
                'actor_type' => $task->actor_type,
                'actor_name' => $task->actor_name,
                'approval_ref' => $attempt->approval_ref,
                'mandate_id' => $report->mandate_id,
                'purpose' => 'report',
            ]);
            $attempt->update(['signature_log_id' => $log->id]);
            $report->update(['signature_log_id' => $log->id]);
        }

        $errors = array_values(array_map(fn ($e) => mb_substr((string) $e, 0, 500), array_filter((array) ($result['errors'] ?? []), 'is_scalar')));
        $receipt = is_string($result['receipt'] ?? null) ? mb_substr($result['receipt'], 0, 80) : null;

        app(SubmissionOrchestrator::class)->recordOutcome($report, $attempt, new GatewayResult($status, $receipt, $errors, ['agent_task' => $task->id]),
            Actor::from($task->actor_type), $task->actor_name, ['agent' => (string) $task->agent?->name]);

        $this->finishTask($task, 'completed', null, ['status' => $status, 'receipt' => $receipt]);

        return $task;
    }

    /** The cabinet's own figures, checked by the same deterministic arithmetic as a real cabinet report, and only shown. */
    private function completeRehearsal(AgentTask $task, array $result): AgentTask
    {
        $draft = PortalSnapshot::normalize(['drafts' => [$result['draft'] ?? []]])['drafts'][0] ?? null;

        if ($draft === null || $draft['type'] !== 'turnover' || $draft['period_start'] === null) {
            return $this->finishTask($task, 'failed', __('tax.agent.errors.protocol'));
        }

        $period = TaxPeriod::fromStart('month', CarbonImmutable::parse($draft['period_start']));
        $computation = CabinetReport::computation(TaxReportType::Turnover, $period, $draft, app(TaxRuleBook::class));
        $filed = collect(PortalCheck::latestCompleted()?->snapshot['reports'] ?? [])->first(fn (array $r) => $r['type'] === 'turnover' && $r['period_start'] === $draft['period_start'] && in_array($r['status'], ['accepted', 'submitted'], true));

        $this->audit->record('agent_task.rehearsal_read', ['task' => $task->id, 'period' => $draft['period_start']], Actor::System, null, (string) ($task->agent->name ?? Actor::System->label()));

        return $this->finishTask($task, 'completed', null, ['rehearsal' => [
            'period_start' => $draft['period_start'],
            'due_date' => $draft['due_date'],
            'rate_bp' => $draft['rate_bp'],
            'lines' => $computation->lines,
            'issues' => $computation->issues,
            'base_minor' => $computation->baseMinor,
            'payable_minor' => $computation->payableMinor,
            'filed_receipt' => $filed['receipt'] ?? null,
        ]]);
    }

    /**
     * The E-IMZO key the accountant signed in with is the company's identity: nobody types an identifier. The first
     * sign-in of a real company that has none yet links it to that cabinet (14 digits: PINFL, 9 digits: STIR). Every later
     * sign-in must show the same identifier, and a company that already has one is never changed here.
     */
    private function linkOnFirstUse(string $identifier, AgentTask $task): void
    {
        $settings = $this->settings();

        if ($settings->is_demo || filled($settings->stir) || filled($settings->pinfl) || ! preg_match('/^(\d{9}|\d{14})$/', $identifier)) {
            return;
        }

        $settings->forceFill([strlen($identifier) === 14 ? 'pinfl' : 'stir' => $identifier])->save();
        $this->company->forget();
        $this->audit->record('company.linked_on_first_sign_in', ['task' => $task->id, 'identifier_tail' => substr($identifier, -3)], Actor::System, __('tax.agent.link_reason_first'), (string) ($task->agent->name ?? Actor::System->label()));
    }

    /** The agent could not finish. Before the form check nothing left the app; after it the outcome is unknown. */
    public function fail(AgentTask $task, string $error, ?string $seenIdentifier = null): AgentTask
    {
        $error = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error) ?? '', 0, 500);

        if ($task->type === 'portal_check') {
            $this->finishTask($task, 'failed', $error, $seenIdentifier === null ? null : ['seen_identifier' => $seenIdentifier]);

            if (($check = PortalCheck::query()->find($task->portal_check_id)) !== null && $check->status === 'running') {
                $this->portalCheck->fail($check, $error);
            }

            return $task;
        }

        if ($task->type === 'pay') {
            $payment = TaxPayment::query()->find($task->tax_payment_id);

            // Before the check nothing left this application; after it the money may have moved: unknown, never "not paid".
            if ($payment !== null && $payment->attempt_started_at !== null) {
                $this->finishTask($task, 'failed', $error);
                $payment->update(['status' => 'unknown', 'error' => $error]);
                $this->audit->record('tax_payment.unknown', ['payment' => $payment->id, 'error' => $error], Actor::System);

                return $task;
            }

            if ($payment !== null) {
                $this->failPayment($task, $payment, $error);

                return $task;
            }

            return $this->finishTask($task, 'failed', $error);
        }

        $report = TaxReport::query()->find($task->tax_report_id);
        $attempt = $task->submission_attempt_id ? SubmissionAttempt::query()->find($task->submission_attempt_id) : null;

        if ($attempt !== null && $attempt->status === 'sending' && $report !== null) {
            $this->finishTask($task, 'failed', $error);
            app(SubmissionOrchestrator::class)->recordOutcome($report, $attempt, new GatewayResult('unknown', errors: [__('tax.agent.errors.failed_after_check'), $error]), Actor::from($task->actor_type), $task->actor_name);

            return $task;
        }

        if ($report !== null) {
            return $this->failBeforeSigning($task, $report, $error);
        }

        return $this->finishTask($task, 'failed', $error);
    }

    /** A person stops a task that has not reached the form check. A submission goes back to "prepared". */
    public function cancel(AgentTask $task, User $user): AgentTask
    {
        if ($task->type === 'pay') {
            $payment = TaxPayment::query()->find($task->tax_payment_id);
            if ($payment === null || $payment->attempt_started_at !== null || ! $task->isOpen()) {
                throw new SubmissionRefused(__('tax.agent.errors.not_cancellable'));
            }

            $this->cancelPayment($payment, $user);

            return $task->refresh();
        }

        if (! $task->isOpen() || $task->submission_attempt_id !== null) {
            throw new SubmissionRefused(__('tax.agent.errors.not_cancellable'));
        }

        $this->finishTask($task, 'cancelled', __('tax.notes.cancelled_by', ['name' => $user->name]));

        if ($task->type === 'submit_report' && ($report = TaxReport::query()->find($task->tax_report_id)) !== null && $report->status === TaxReportStatus::Submitting) {
            app(SubmissionOrchestrator::class)->resetApproval($report, __('tax.notes.cancelled_by', ['name' => $user->name]));
        }

        if ($task->type === 'portal_check' && ($check = PortalCheck::query()->find($task->portal_check_id)) !== null && $check->status === 'running') {
            $this->portalCheck->fail($check, __('tax.notes.cancelled_by', ['name' => $user->name]));
        }

        $this->audit->record('agent_task.cancelled', ['task' => $task->id]);

        return $task;
    }

    /** Lost agents and tasks nobody picked up. Runs on every claim and hourly. */
    public function expireLeases(): int
    {
        $count = 0;

        $lost = AgentTask::query()->whereIn('status', ['claimed', 'needs_user'])->where('lease_expires_at', '<', now())->get();
        foreach ($lost as $task) {
            $this->fail($task, __('tax.agent.errors.lost'));
            $count++;
        }

        $stale = AgentTask::query()->where('status', 'queued')->where('created_at', '<', now()->subDay())->get();
        foreach ($stale as $task) {
            $this->fail($task, __('tax.agent.errors.never_picked_up'));
            $count++;
        }

        return $count;
    }

    private function failBeforeSigning(AgentTask $task, TaxReport $report, string $error): AgentTask
    {
        $this->finishTask($task, 'failed', $error);

        if ($report->status === TaxReportStatus::Submitting) {
            app(SubmissionOrchestrator::class)->block($report, [...($report->gate_results ?? []), ['key' => 'agent', 'passed' => false, 'params' => ['errors' => $error]]],
                Actor::from($task->actor_type), $task->actor_name);
        }

        return $task;
    }

    private function attemptWithoutCheck(AgentTask $task, TaxReport $report): SubmissionAttempt
    {
        $attempt = SubmissionAttempt::create([
            'tax_report_id' => $report->id,
            'idempotency_key' => SubmissionAttempt::keyFor($report),
            'version_hash' => $report->version_hash,
            'gateway' => 'desktop',
            'status' => 'sending',
            'actor_type' => $task->actor_type,
            'actor_name' => $task->actor_name,
            'started_at' => now(),
        ]);
        $task->update(['submission_attempt_id' => $attempt->id]);

        return $attempt;
    }

    private function stillDeliverable(AgentTask $task): bool
    {
        $report = TaxReport::query()->find($task->tax_report_id);

        return $report !== null
            && $report->status === TaxReportStatus::Submitting
            && $task->version_hash !== null
            && hash_equals($report->version_hash, $task->version_hash)
            && $report->isApprovalValid();
    }

    private function assertClaimed(AgentTask $task): void
    {
        if (! in_array($task->status, ['claimed', 'needs_user'], true)) {
            throw new SubmissionRefused(__('tax.agent.errors.not_claimed'));
        }
    }

    private function finishTask(AgentTask $task, string $status, ?string $error, ?array $result = null): AgentTask
    {
        $task->update(['status' => $status, 'error' => $error, 'result' => $result ?? $task->result, 'waiting_for' => null, 'finished_at' => now()]);
        $this->audit->record('agent_task.'.$status, ['task' => $task->id, 'type' => $task->type, 'error' => $error], Actor::System, null, (string) ($task->agent->name ?? Actor::System->label()));

        return $task;
    }

    /** @return array<string, mixed> what every task needs to find the right cabinet and key; no secrets */
    private function companyPayload(): array
    {
        $settings = $this->settings();

        return [
            'company' => ['name' => $settings->name, 'tin' => (string) $settings->stir, 'pinfl' => $settings->pinfl, 'district' => (string) $settings->tax_district],
            // An entrepreneur's cabinet is the individual login (its identifier is the PINFL); a company's is the legal one.
            // An entrepreneur is one switch away from the private person's cabinet: debts, demands, payments and reports are there.
            'portal' => ['url' => 'https://my.soliq.uz', 'login_type' => $settings->pinfl ? 'individual' : 'legal', 'entrepreneur' => filled($settings->pinfl)],
        ];
    }

    private function settings(): CompanySetting
    {
        $this->company->forget();

        return $this->company->settings();
    }
}
