<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\StoreTaxPaymentRequest;
use App\Models\CabinetPayment;
use App\Models\PortalCheck;
use App\Models\TaxPayment;
use App\Payments\PaymentCatalog;
use App\Payments\PaymentOrchestrator;
use App\Submission\SubmissionRefused;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Tax payments: what the cabinet says is owed and paid, and the form that pays (the robot makes the payment in the cabinet). */
class TaxPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('view-reports');

        $year = (string) now()->year;
        $check = PortalCheck::latestCompleted();

        return view('tax.payments', [
            'payments' => CabinetPayment::query()->orderByDesc('order_date')->orderByDesc('id')->paginate(15),
            // Paid by the bank this year, per tax code.
            'totals' => CabinetPayment::query()->where('is_paid', true)->where('year', $year)->selectRaw('tax_code, count(*) as orders, sum(amount_minor) as total_minor')->groupBy('tax_code')->orderBy('tax_code')->get(),
            'mine' => TaxPayment::query()->latest('id')->limit(10)->get(),
            // What the cabinet says is owed, per tax, from the last read.
            'standing' => $this->standing($check?->snapshot['balances'] ?? []),
            'year' => $year,
            'check' => $check,
            'token' => Str::random(32),
            'prefill' => [
                'tax_code' => in_array($request->query('tax'), PaymentCatalog::taxCodes(), true) ? $request->query('tax') : '100',
                'kind' => in_array($request->query('kind'), PaymentCatalog::KINDS, true) ? $request->query('kind') : '08101',
                'amount' => preg_match('/^\d{1,15}$/', (string) $request->query('amount')) ? (string) $request->query('amount') : '',
            ],
            'taxCodes' => PaymentCatalog::taxCodes(),
            'kinds' => PaymentCatalog::KINDS,
            'canPay' => $request->user()->can(Permission::ReportsApprove->value),
        ]);
    }

    /**
     * What each tax stands at, from the cabinet's personal tax card, and what the cabinet has been paid since the card's date:
     * the card is as of one day, the payment orders are newer. The remaining figure is arithmetic on both, marked as an estimate.
     *
     * @param  list<array<string, mixed>>  $balances
     * @return list<array<string, mixed>>
     */
    private function standing(array $balances): array
    {
        return array_map(function (array $b) {
            $code = $b['code'] ?? (preg_match('/^(\d{1,4})\b/', (string) $b['name'], $m) ? $m[1] : null);
            $paidSince = $code !== null && ($b['as_of'] ?? null) !== null
                ? (int) CabinetPayment::query()->where('is_paid', true)->where('tax_code', $code)->whereDate('order_date', '>', $b['as_of'])->sum('amount_minor')
                : 0;

            return $b + ['code' => $code, 'paid_since_minor' => $paidSince, 'remaining_minor' => max(0, $b['debt_minor'] + $b['penalty_minor'] - $paidSince)];
        }, $balances);
    }

    public function store(StoreTaxPaymentRequest $request, PaymentOrchestrator $payments): RedirectResponse
    {
        try {
            $payments->request([
                'tax_code' => $request->validated('tax_code'),
                'kind' => $request->validated('kind'),
                'amount_minor' => $request->amountMinor(),
                'purpose' => $request->validated('purpose'),
                'client_token' => $request->validated('client_token'),
                'dry_run' => (bool) $request->validated('dry_run'),
            ], $request->user());
        } catch (SubmissionRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.payments')->with('status', $request->boolean('dry_run') ? __('tax.payments.queued_dry') : __('tax.payments.queued'));
    }

    public function cancel(Request $request, TaxPayment $payment, PaymentOrchestrator $payments): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);

        try {
            $payments->cancel($payment, $request->user());
        } catch (SubmissionRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.payments')->with('status', __('tax.payments.cancelled'));
    }
}
