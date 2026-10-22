<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountRequest;
use App\Models\Account;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AccountController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Account::class);

        return view('bank.account-form', ['account' => new Account(['type' => 'bank'])]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        Account::create($request->accountData());

        return redirect()->route('bank.index')->with('status', __('ui.bank.account_created'));
    }

    public function edit(Account $account): View
    {
        $this->authorize('update', $account);

        return view('bank.account-form', compact('account'));
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $account->update($request->accountData());

        return redirect()->route('bank.index')->with('status', __('ui.bank.account_updated'));
    }
}
