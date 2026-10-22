<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Document;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only figures for the dashboard cards. All amounts are integer minor units;
 * the "*_label" fields are ready-to-display strings.
 */
class DashboardMetrics
{
    public function balance(): array
    {
        $accounts = Account::query()->where('is_active', true)->orderBy('type')->orderBy('name')->get();

        $rows = $accounts->map(fn (Account $account) => [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'balance_minor' => $account->balanceMinor(),
        ]);

        $total = (int) $rows->sum('balance_minor');

        return [
            'total_minor' => $total,
            'total_label' => Money::format($total, false),
            'currency' => __('ui.currency.uzs'),
            'accounts' => $rows->map(fn (array $row) => $row + [
                'balance_label' => Money::format($row['balance_minor']),
            ])->values(),
        ];
    }

    public function weekly(): array
    {
        $end = CarbonImmutable::now();
        $thisWeek = $this->sum(TransactionType::Income, $end->subDays(7), $end);
        $lastWeek = $this->sum(TransactionType::Income, $end->subDays(14), $end->subDays(7));

        return [
            'amount_minor' => $thisWeek,
            'amount_label' => Money::format($thisWeek),
            'change_percent' => $this->changePercent($thisWeek, $lastWeek),
        ];
    }

    /** Income vs expense per month: the selected range ("monthly") or the last 12 months ("annual"). */
    public function trend(CarbonImmutable $from, CarbonImmutable $to, string $mode): array
    {
        if ($mode === 'annual') {
            $to = CarbonImmutable::now()->endOfMonth();
            $from = CarbonImmutable::now()->startOfMonth()->subMonths(11);
        }

        $months = [];
        for ($cursor = $from->startOfMonth(); $cursor <= $to; $cursor = $cursor->addMonth()) {
            $months[$cursor->format('Y-m')] = ['income' => 0, 'expense' => 0, 'month' => (int) $cursor->format('n')];
        }

        $this->transactionsBetween($from->startOfMonth(), $to->endOfDay())
            ->each(function (Transaction $transaction) use (&$months) {
                $key = $transaction->occurred_at->format('Y-m');

                if (isset($months[$key])) {
                    $months[$key][$transaction->type->value] += $transaction->amount_minor;
                }
            });

        $months = collect($months)->values();

        return [
            'labels' => $months->map(fn (array $m) => __('ui.months_short.'.$m['month']))->all(),
            'income' => $months->map(fn (array $m) => intdiv($m['income'], 100))->all(),
            'expense' => $months->map(fn (array $m) => intdiv($m['expense'], 100))->all(),
            'income_labels' => $months->map(fn (array $m) => Money::format($m['income']))->all(),
            'expense_labels' => $months->map(fn (array $m) => Money::format($m['expense']))->all(),
            'income_total_label' => Money::format((int) $months->sum('income')),
            'expense_total_label' => Money::format((int) $months->sum('expense')),
            'has_data' => $months->sum(fn (array $m) => $m['income'] + $m['expense']) > 0,
        ];
    }

    /** Daily-to-weekly net cash flow for the area chart, with a running balance. */
    public function cashflow(CarbonImmutable $from, CarbonImmutable $to, string $series): array
    {
        $type = $series === 'expense' ? TransactionType::Expense : TransactionType::Income;

        $buckets = [];
        for ($cursor = $from->startOfWeek(); $cursor <= $to; $cursor = $cursor->addWeek()) {
            $buckets[$cursor->format('o-W')] = ['label' => $cursor->format('d.m'), 'sum' => 0];
        }

        $transactions = $this->transactionsBetween($from->startOfWeek(), $to->endOfDay());

        $transactions->where('type', $type)->each(function (Transaction $transaction) use (&$buckets) {
            $key = $transaction->occurred_at->format('o-W');

            if (isset($buckets[$key])) {
                $buckets[$key]['sum'] += $transaction->amount_minor;
            }
        });

        $buckets = collect($buckets)->values();
        $total = (int) $buckets->sum('sum');
        $net = $transactions->sum(fn (Transaction $t) => $t->signedAmountMinor());

        return [
            'labels' => $buckets->pluck('label')->all(),
            'values' => $buckets->map(fn (array $b) => intdiv($b['sum'], 100))->all(),
            'value_labels' => $buckets->map(fn (array $b) => Money::format($b['sum']))->all(),
            'total_label' => Money::format($total, false),
            'net_label' => Money::format((int) $net),
            'currency' => __('ui.currency.uzs'),
            'has_data' => $total > 0,
        ];
    }

    public function payments(int $limit = 6): array
    {
        $rows = Transaction::query()
            ->with(['contractor', 'category', 'account'])
            ->latest('occurred_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        return [
            'rows' => $rows->map(fn (Transaction $t) => [
                'id' => $t->id,
                'title' => $t->title(),
                'subtitle' => $t->category?->label() ?? $t->account->name,
                'initial' => mb_strtoupper(mb_substr($t->title(), 0, 1)),
                'type' => $t->type->value,
                'date' => $t->occurred_at->format('d.m.Y'),
                'time' => $t->occurred_at->format('H:i'),
                'status' => $t->status,
                'status_label' => __('ui.bank.statuses.'.$t->status),
                'amount_label' => ($t->type === TransactionType::Income ? '+' : '−').Money::format($t->amount_minor),
            ])->all(),
            'has_data' => $rows->isNotEmpty(),
        ];
    }

    public function receivables(): array
    {
        $open = Document::query()->where('status', DocumentStatus::Sent)->with('contractor');

        $receivable = (clone $open)->where('direction', 'out')->get();
        $payable = (clone $open)->where('direction', 'in')->get();

        $overdue = $receivable->filter(fn (Document $d) => $d->isOverdue());

        $people = $overdue->groupBy('contractor_id')
            ->map(fn (Collection $docs) => $docs->first()->contractor)
            ->take(4)
            ->map(fn ($contractor) => ['name' => $contractor->name, 'initial' => $contractor->initials()])
            ->values();

        $receivableTotal = (int) $receivable->sum('total_minor');
        $payableTotal = (int) $payable->sum('total_minor');

        return [
            'receivable_label' => Money::format($receivableTotal),
            'payable_label' => Money::format($payableTotal),
            'receivable_count' => $receivable->count(),
            'payable_count' => $payable->count(),
            'overdue_count' => $overdue->count(),
            'overdue_total_label' => Money::format((int) $overdue->sum('total_minor')),
            'overdue_people' => $people->all(),
            'overdue_extra' => max(0, $overdue->pluck('contractor_id')->unique()->count() - $people->count()),
            'has_data' => $receivable->isNotEmpty() || $payable->isNotEmpty(),
        ];
    }

    private function sum(TransactionType $type, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) Transaction::query()
            ->completed()
            ->where('type', $type)
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('amount_minor');
    }

    private function changePercent(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) * 100) / $previous, 1);
    }

    /** @return Collection<int, Transaction> */
    private function transactionsBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Transaction::query()
            ->completed()
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['id', 'type', 'amount_minor', 'occurred_at']);
    }
}
