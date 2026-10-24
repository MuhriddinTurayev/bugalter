<?php

namespace App\Submission;

use App\Enums\Actor;
use App\Enums\Permission;
use App\Enums\TaxReportStatus;
use App\Models\AgentTask;
use App\Models\CompanySetting;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;
use App\Models\User;
use App\Services\Audit;
use App\Services\CompanyNotifier;
use App\Services\CurrentCompany;
use App\Submission\Gateway\GatewayResult;
use App\Submission\Gateway\TaxAuthorityGateway;
use App\Submission\Gateway\VerifiesSubmissions;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Settles deliveries whose outcome is open (submitted, unknown, or a "sending" that never finished). It asks the
 * gateway what the authority holds; only the authority's own answer turns a report green, red or resendable.
 * A person may settle it too, after looking in the tax cabinet, with a written note. Nothing here ever sends
 * a report: a report the authority did not receive goes back to "prepared" and needs a fresh approval.
 */
class SubmissionVerifier
{
    /** A delivery still "sending" after this long was interrupted (the job died or timed out). */
    public const STALE_SENDING_MINUTES = 20;

    public function __construct(
        private readonly TaxAuthorityGateway $gateway,
        private readonly SubmissionOrchestrator $orchestrator,
        private readonly CurrentCompany $company,
        private readonly Audit $audit,
        private readonly CompanyNotifier $notifier,
    ) {}

    /** Hourly (AutopilotRunner): recover interrupted deliveries, then ask about every open one. */
    public function verifyPending(): array
    {
        $summary = ['interrupted' => $this->recoverInterrupted(), 'checked' => 0, 'settled' => 0];

        $reports = TaxReport::query()->whereIn('status', [TaxReportStatus::Submitted, TaxReportStatus::Unknown])->get();

        foreach ($reports as $report) {
            $before = $report->status;
            $summary['checked']++;

            try {
                $this->verify($report, Actor::System);
            } catch (SubmissionRefused) {
                // Being delivered or verified right now.
            } catch (Throwable $e) {
                report($e);
            }

            if ($report->status !== $before) {
                $summary['settled']++;
            }
        }

        return $summary;
    }

    /** A delivery left in "sending" crashed between signing and the authority's answer: its outcome is unknown. */
    public function recoverInterrupted(): int
    {
        $stale = SubmissionAttempt::query()
            ->where('status', 'sending')
            ->where('started_at', '<', now()->subMinutes(self::STALE_SENDING_MINUTES))
            ->get();

        $recovered = 0;

        foreach ($stale as $attempt) {
            $report = TaxReport::query()->find($attempt->tax_report_id);

            try {
                $report !== null && $this->locked($report, function () use ($report, $attempt, &$recovered) {
                    // A desktop agent still reporting in is mid-delivery (the accountant may be typing the E-IMZO password).
                    $alive = AgentTask::query()->where('submission_attempt_id', $attempt->id)->get()->contains(fn (AgentTask $task) => $task->isAlive());

                    if ($attempt->fresh()?->status !== 'sending' || $alive) {
                        return;
                    }

                    $this->orchestrator->recordOutcome($report, $attempt, new GatewayResult('unknown', errors: [__('tax.errors.delivery_interrupted')]), Actor::System);
                    $recovered++;
                });
            } catch (SubmissionRefused) {
                // Still being delivered right now; the next pass looks again.
            }
        }

        return $recovered;
    }

    /** Asks the gateway about the report's open delivery. Returns the report, settled or still open. */
    public function verify(TaxReport $report, Actor $actor = Actor::System, ?string $actorName = null): TaxReport
    {
        return $this->locked($report, function () use ($report, $actor, $actorName) {
            if (! $report->status->awaitsVerification()) {
                return $report;
            }

            $attempt = $this->openAttempt($report);

            if ($attempt->gateway !== $this->gateway->name() || ! $this->gateway instanceof VerifiesSubmissions) {
                // The gateway that delivered it is no longer configured: only a person can settle it now.
                $attempt->update(['last_checked_at' => now()]);

                return $report;
            }

            try {
                $result = $this->gateway->verify($report, $this->settings(), $attempt);
            } catch (Throwable $e) {
                report($e);
                $result = new GatewayResult('unknown', errors: [__('tax.errors.verification_failed')]);
            }

            $by = 'gateway:'.$this->gateway->name();

            return match (true) {
                $result->status === 'not_found' => $this->notReceived($report, $attempt, $by, $actor, $actorName, null),
                $result->status === 'unknown' => $this->stillOpen($report, $attempt, $result),
                // Already "submitted" and still processing: nothing new to record.
                $result->status === 'submitted' && $report->status === TaxReportStatus::Submitted => $this->stillOpen($report, $attempt, $result),
                default => $this->orchestrator->recordOutcome($report, $attempt, $result, $actor, $actorName, verifiedBy: $by),
            };
        });
    }

    /**
     * A person with reports.approve and reports.submit settles the outcome after checking the tax cabinet.
     * "not_received" is allowed only while the outcome is unknown: a report the authority acknowledged is
     * never declared missing by hand.
     *
     * @param  'accepted'|'rejected'|'not_received'  $outcome
     */
    public function resolveManually(TaxReport $report, User $user, string $outcome, ?string $receipt, string $note): TaxReport
    {
        if (! $user->hasPermission(Permission::ReportsApprove) || ! $user->hasPermission(Permission::ReportsSubmit)) {
            throw new SubmissionRefused(__('tax.errors.no_permission'));
        }

        return $this->locked($report, function () use ($report, $user, $outcome, $receipt, $note) {
            if (! $report->status->awaitsVerification()) {
                throw new SubmissionRefused(__('tax.errors.not_awaiting_verification'));
            }

            if ($outcome === 'not_received' && $report->status !== TaxReportStatus::Unknown) {
                throw new SubmissionRefused(__('tax.errors.not_received_only_unknown'));
            }

            $attempt = $this->openAttempt($report);
            $by = 'user:'.$user->name;

            $this->audit->record('tax_report.resolved_manually', [
                'report' => $report->id, 'attempt' => $attempt->id, 'outcome' => $outcome, 'receipt' => $receipt,
            ], Actor::User, $note, $user->name);

            if ($outcome === 'not_received') {
                return $this->notReceived($report, $attempt, $by, Actor::User, $user->name, $note);
            }

            $attempt->update(['resolution_note' => $note]);
            $result = new GatewayResult($outcome, $receipt, $outcome === 'rejected' ? [$note] : [], ['resolved_by' => $user->name]);

            return $this->orchestrator->recordOutcome($report, $attempt, $result, Actor::User, $user->name, verifiedBy: $by);
        });
    }

    /** The authority has no record of the delivery: the report goes back for a fresh approval (same idempotency key). */
    private function notReceived(TaxReport $report, SubmissionAttempt $attempt, string $by, Actor $actor, ?string $actorName, ?string $note): TaxReport
    {
        $attempt->update([
            'status' => 'not_received',
            'last_checked_at' => now(),
            'verified_at' => now(),
            'verified_by' => $by,
            'resolution_note' => $note,
        ]);

        $report->update(['submitted_at' => null, 'receipt_number' => null]);
        $this->orchestrator->resetApproval($report, __('tax.notes.not_received'), hold: false);

        $this->audit->record('tax_report.not_received', ['report' => $report->id, 'attempt' => $attempt->id, 'verified_by' => $by], $actor, $note, $actorName);
        $this->notifier->owners('tax.notifications.not_received', ['report' => $report->title()], route('tax.reports.show', $report, false));

        return $report;
    }

    private function stillOpen(TaxReport $report, SubmissionAttempt $attempt, GatewayResult $result): TaxReport
    {
        $attempt->update(['last_checked_at' => now(), 'errors' => $result->errors === [] ? $attempt->errors : $result->errors]);

        return $report;
    }

    /**
     * The delivery to settle. A report sent before attempts were recorded gets one reconstructed from the report,
     * so it can still be settled.
     */
    private function openAttempt(TaxReport $report): SubmissionAttempt
    {
        return SubmissionAttempt::openFor($report) ?? SubmissionAttempt::create([
            'tax_report_id' => $report->id,
            'idempotency_key' => SubmissionAttempt::keyFor($report),
            'version_hash' => $report->version_hash,
            'gateway' => (string) ($report->authority_response['gateway'] ?? $this->gateway->name()),
            'status' => $report->status === TaxReportStatus::Unknown ? 'unknown' : 'submitted',
            'receipt_number' => $report->receipt_number,
            'signature_log_id' => $report->signature_log_id,
            'actor_type' => Actor::System->value,
            'actor_name' => Actor::System->label(),
            'started_at' => $report->submitted_at ?? now(),
            'finished_at' => $report->submitted_at ?? now(),
        ]);
    }

    /**
     * Same lock as SubmissionOrchestrator::submit(), so a verification never races a delivery of the same report.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(TaxReport $report, callable $callback): mixed
    {
        $lock = Cache::lock('submission:'.$report->id, 900);

        if (! $lock->get()) {
            throw new SubmissionRefused(__('tax.errors.submission_in_progress'));
        }

        try {
            $report->refresh();

            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function settings(): CompanySetting
    {
        $this->company->forget();

        return $this->company->settings();
    }
}
