<?php

namespace App\Payments;

use App\Agent\AgentTasks;
use App\Enums\Actor;
use App\Models\CabinetPayment;
use App\Models\DesktopAgent;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\Audit;
use App\Services\CurrentCompany;
use App\Submission\SubmissionRefused;
use Illuminate\Support\Facades\DB;

/**
 * "I want to pay this much tax": the only way a payment starts. A person types the tax, the payment type and the amount in
 * the platform and presses "pay"; that press is the approval, bound to exactly those values (the version hash). The desktop
 * agent then makes the payment in the tax cabinet, and before it presses the cabinet's own confirm button Laravel compares what
 * the cabinet form says with what was approved (AgentTasks::paymentCheck). Nothing here (or the AI) can start a payment alone.
 *
 * An outcome that is not known is never retried: the payment stays "unknown" and is settled only by what the cabinet's own list
 * of payment orders shows (PaymentVerifier).
 */
class PaymentOrchestrator
{
    public function __construct(
        private readonly AgentTasks $tasks,
        private readonly CurrentCompany $company,
        private readonly Audit $audit,
    ) {}

    /** @param  array{tax_code: string, kind: string, amount_minor: int, purpose?: ?string, client_token: string, dry_run?: bool}  $data */
    public function request(array $data, User $user): TaxPayment
    {
        $settings = $this->company->settings();

        // The same opened form pressed twice is the same payment.
        if (($existing = TaxPayment::query()->where('client_token', $data['client_token'])->first()) !== null) {
            return $existing;
        }

        if ($settings->is_demo) {
            throw new SubmissionRefused(__('tax.payments.errors.demo'));
        }

        if (! in_array($data['tax_code'], PaymentCatalog::taxCodes(), true) || ! in_array($data['kind'], PaymentCatalog::KINDS, true)) {
            throw new SubmissionRefused(__('tax.payments.errors.unknown_type'));
        }

        if ($data['amount_minor'] < 100 || $data['amount_minor'] > PaymentCatalog::MAX_MINOR) {
            throw new SubmissionRefused(__('tax.payments.errors.amount'));
        }

        if (! DesktopAgent::query()->whereNull('revoked_at')->where('last_seen_at', '>=', now()->subMinutes(3))->exists()) {
            throw new SubmissionRefused(__('tax.payments.errors.no_agent'));
        }

        if (blank($settings->pinfl) && blank($settings->stir)) {
            throw new SubmissionRefused(__('tax.payments.errors.not_linked'));
        }

        return DB::transaction(function () use ($data, $user, $settings) {
            // One payment at a time per tax; and a payment whose outcome is still unknown blocks an identical one: it may have gone through.
            if (TaxPayment::query()->where('tax_code', $data['tax_code'])->whereIn('status', TaxPayment::OPEN)->lockForUpdate()->exists()) {
                throw new SubmissionRefused(__('tax.payments.errors.in_progress'));
            }

            if (empty($data['dry_run']) && TaxPayment::query()->where('tax_code', $data['tax_code'])->where('amount_minor', $data['amount_minor'])->whereIn('status', ['unknown', 'submitted'])->where('created_at', '>=', now()->subDay())->exists()) {
                throw new SubmissionRefused(__('tax.payments.errors.unsettled'));
            }

            $purpose = trim((string) ($data['purpose'] ?? ''));
            $hash = hash('sha256', json_encode([
                $data['tax_code'], $data['kind'], $data['amount_minor'], $purpose, (bool) ($data['dry_run'] ?? false),
                (string) ($settings->pinfl ?: $settings->stir), (string) $settings->tax_region, (string) $settings->tax_district,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            $payment = TaxPayment::create([
                'client_token' => $data['client_token'],
                'tax_code' => $data['tax_code'],
                'kind' => $data['kind'],
                'amount_minor' => $data['amount_minor'],
                'purpose' => $purpose,
                'dry_run' => (bool) ($data['dry_run'] ?? false),
                'status' => 'queued',
                'version_hash' => $hash,
                'idempotency_key' => hash('sha256', 'payment|'.$data['client_token'].'|'.$hash),
                'approved_by' => $user->id,
                'approved_by_name' => $user->name,
                'approved_at' => now(),
            ]);

            $this->tasks->dispatchPayment($payment, Actor::User, $user->name);
            $this->audit->record('tax_payment.approved', [
                'payment' => $payment->id, 'tax_code' => $payment->tax_code, 'kind' => $payment->kind, 'amount_minor' => $payment->amount_minor, 'version' => substr($hash, 0, 12),
            ], Actor::User, null, $user->name);

            return $payment;
        });
    }

    /** A payment that has not reached the cabinet's confirm button yet can be taken back. */
    public function cancel(TaxPayment $payment, User $user): TaxPayment
    {
        if ($payment->status !== 'queued' && ! ($payment->status === 'sending' && $payment->attempt_started_at === null)) {
            throw new SubmissionRefused(__('tax.payments.errors.not_cancellable'));
        }

        $this->tasks->cancelPayment($payment, $user);

        return $payment->refresh();
    }

    /**
     * Settles payments whose outcome was not known by what the cabinet's list of payment orders shows: an order for the same tax and
     * the same amount, made on or after the day the attempt started, that no other platform payment has claimed. Never invents one.
     */
    public function reconcile(): int
    {
        $settled = 0;

        foreach (TaxPayment::query()->whereIn('status', ['sending', 'unknown', 'submitted'])->whereNotNull('attempt_started_at')->get() as $payment) {
            $claimed = TaxPayment::query()->whereNotNull('order_number')->where('id', '!=', $payment->id)->get(['order_number', 'order_date', 'tax_code']);

            $order = CabinetPayment::query()
                ->where('tax_code', $payment->tax_code)
                ->where('amount_minor', $payment->amount_minor)
                ->whereDate('order_date', '>=', $payment->attempt_started_at->toDateString())
                ->orderByDesc('id')
                ->get()
                ->first(fn (CabinetPayment $o) => ! $claimed->contains(fn ($c) => $c->order_number === $o->order_number && $c->tax_code === $o->tax_code && $c->order_date?->isSameDay($o->order_date)));

            if ($order === null) {
                continue;
            }

            $payment->update([
                'order_number' => $order->order_number,
                'order_date' => $order->order_date,
                'status' => $order->is_paid ? 'paid' : 'submitted',
                'error' => null,
            ]);
            $this->audit->record('tax_payment.settled', ['payment' => $payment->id, 'order' => $order->order_number, 'paid' => $order->is_paid], Actor::System);
            $settled++;
        }

        return $settled;
    }
}
