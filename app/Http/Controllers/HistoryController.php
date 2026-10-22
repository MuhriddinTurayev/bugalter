<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('viewAny', Transaction::class);

        return view('history.index', [
            'transactions' => BankController::filteredTransactionsQuery($request)->paginate(20)->withQueryString(),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
