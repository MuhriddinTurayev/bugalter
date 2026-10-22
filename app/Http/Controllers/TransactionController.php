<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\TransactionRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Contractor;
use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function create(Request $request): View
    {
        $this->authorize('create', Transaction::class);

        return view('bank.transaction-form', [
            'type' => TransactionType::tryFrom((string) $request->query('type')) ?? TransactionType::Income,
            'accounts' => Account::query()->where('is_active', true)->orderBy('name')->get(),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(),
            'contractors' => Contractor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(TransactionRequest $request): RedirectResponse
    {
        Transaction::create($request->transactionData());

        return redirect()->route('bank.index')->with('status', __('ui.bank.transaction_created'));
    }
}
