<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Transaction::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TransactionType::class)],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('is_active', true)->where('type', $this->input('type'))],
            'contractor_id' => ['nullable', Rule::exists('contractors', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'string'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $minor = Money::parseToMinor($this->input('amount'));

            if ($minor === null || $minor <= 0) {
                $validator->errors()->add('amount', __('ui.bank.errors.amount'));
            }
        });
    }

    public function attributes(): array
    {
        return [
            'account_id' => __('ui.bank.fields.account'),
            'category_id' => __('ui.bank.fields.category'),
            'contractor_id' => __('ui.bank.fields.contractor'),
            'amount' => __('ui.bank.fields.amount'),
            'occurred_on' => __('ui.bank.fields.date'),
            'description' => __('ui.bank.fields.description'),
            'reference' => __('ui.bank.fields.reference'),
        ];
    }

    /** @return array<string, mixed> */
    public function transactionData(): array
    {
        $data = $this->validated();

        return [
            'type' => $data['type'],
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'] ?? null,
            'contractor_id' => $data['contractor_id'] ?? null,
            'amount_minor' => Money::parseToMinor($data['amount']),
            'occurred_at' => $data['occurred_on'].' '.now()->format('H:i:s'),
            'description' => $data['description'] ?? null,
            'reference' => $data['reference'] ?? null,
            'status' => 'completed',
            'created_by' => $this->user()->id,
        ];
    }
}
