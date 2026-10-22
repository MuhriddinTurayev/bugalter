<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BankController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Transaction::class);

        return view('bank.index', [
            'accounts' => Account::query()->orderBy('type')->orderBy('name')->get(),
            'transactions' => $this->filteredTransactions($request)->paginate(15)->withQueryString(),
            'categories' => Category::query()->where('is_active', true)->orderBy('type')->orderBy('name')->get(),
            'selectedAccount' => $request->integer('account_id') ?: null,
        ]);
    }

    /** Shared with the History page. */
    public static function filteredTransactionsQuery(Request $request)
    {
        $type = TransactionType::tryFrom((string) $request->query('type'));

        return Transaction::query()
            ->with(['account', 'category', 'contractor'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($request->integer('account_id'), fn ($q, $id) => $q->where('account_id', $id))
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.trim((string) $request->query('q')).'%';
                $q->where(fn ($inner) => $inner
                    ->where('description', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhereHas('contractor', fn ($c) => $c->where('name', 'like', $like)));
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $request->query('to')))
            ->latest('occurred_at')
            ->latest('id');
    }

    private function filteredTransactions(Request $request)
    {
        return self::filteredTransactionsQuery($request);
    }
}
