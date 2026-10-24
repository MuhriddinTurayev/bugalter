<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Support\Money;
use App\Tax\TaxRuleBook;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __invoke(Request $request): View
    {
        $from = $this->date($request->query('from'), now()->startOfMonth());
        $to = $this->date($request->query('to'), now());

        $transactions = Transaction::query()
            ->completed()
            ->with('category')
            ->whereBetween('occurred_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['id', 'category_id', 'type', 'amount_minor']);

        $byCategory = fn (TransactionType $type) => $transactions
            ->where('type', $type)
            ->groupBy(fn (Transaction $t) => $t->category?->label() ?? __('ui.reports.uncategorized'))
            ->map(fn ($group) => (int) $group->sum('amount_minor'))
            ->sortDesc();

        $income = (int) $transactions->where('type', TransactionType::Income)->sum('amount_minor');
        $expense = (int) $transactions->where('type', TransactionType::Expense)->sum('amount_minor');

        return view('reports.index', [
            'from' => $from,
            'to' => $to,
            'income' => Money::format($income),
            'expense' => Money::format($expense),
            'net' => Money::format($income - $expense),
            'incomeRows' => $byCategory(TransactionType::Income),
            'expenseRows' => $byCategory(TransactionType::Expense),
            // Tax figures are never invented: they come from the rules tables through the calculators (Taxes page).
            'taxRulesLoaded' => app(TaxRuleBook::class)->isLoaded(),
        ]);
    }

    private function date(?string $value, CarbonImmutable|Carbon $default): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : CarbonImmutable::instance($default);
        } catch (\Throwable) {
            return CarbonImmutable::instance($default);
        }
    }
}
