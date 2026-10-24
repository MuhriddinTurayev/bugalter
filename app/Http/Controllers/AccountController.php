<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Services\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AccountController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Account::class);

        return view('bank.account-form', ['account' => new Account(['type' => 'bank'])]);
    }

    public function store(AccountRequest $request, Audit $audit): RedirectResponse
    {
        $account = Account::create($request->accountData());
        $audit->record('account.created', ['account' => $account->id, 'name' => $account->name]);

        return redirect()->route('bank.index')->with('status', __('ui.bank.account_created'));
    }

    public function edit(Account $account): View
    {
        $this->authorize('update', $account);

        return view('bank.account-form', compact('account'));
    }

    public function update(AccountRequest $request, Account $account, Audit $audit): RedirectResponse
    {
        $account->update($request->accountData());
        $audit->record('account.updated', ['account' => $account->id, 'name' => $account->name, 'changed' => array_keys($account->getChanges())]);

        return redirect()->route('bank.index')->with('status', __('ui.bank.account_updated'));
    }
}
