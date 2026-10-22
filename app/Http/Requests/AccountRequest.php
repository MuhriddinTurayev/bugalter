<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            ? $this->user()->can('update', $account)
            : $this->user()->can('create', Account::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'regex:/^\d{20}$/'],
            'opening_balance' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('opening_balance') && Money::parseToMinor($this->input('opening_balance')) === null) {
                $validator->errors()->add('opening_balance', __('ui.bank.errors.amount'));
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name' => __('ui.bank.fields.name'),
            'type' => __('ui.bank.fields.type'),
            'bank_name' => __('ui.bank.fields.bank_name'),
            'account_number' => __('ui.bank.fields.account_number'),
            'opening_balance' => __('ui.bank.fields.opening_balance'),
        ];
    }

    public function messages(): array
    {
        return ['account_number.regex' => __('ui.contractors.errors.bank_account')];
    }

    /** @return array<string, mixed> */
    public function accountData(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'bank_name' => $data['type'] === AccountType::Bank->value ? ($data['bank_name'] ?? null) : null,
            'account_number' => $data['type'] === AccountType::Bank->value ? ($data['account_number'] ?? null) : null,
            'opening_balance_minor' => Money::parseToMinor($data['opening_balance'] ?? null) ?? 0,
        ];
    }
}
