<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Payments\PaymentCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Pay this much tax": the tax, the payment type and the amount in so'm (the only place an amount is typed). */
class StoreTaxPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ReportsApprove->value);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tax_code' => ['required', Rule::in(PaymentCatalog::taxCodes())],
            'kind' => ['required', Rule::in(PaymentCatalog::KINDS)],
            'amount' => ['required', 'string', 'regex:/^\s*\d[\d\s]{0,17}([.,]\d{1,2})?\s*$/'],
            'purpose' => ['nullable', 'string', 'max:200'],
            'dry_run' => ['nullable', 'boolean'],
            'client_token' => ['required', 'string', 'min:16', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /** The amount in whole tiyin, by string arithmetic (never a float). */
    public function amountMinor(): int
    {
        $text = preg_replace('/\s+/', '', (string) $this->validated('amount')) ?? '';
        [$whole, $fraction] = array_pad(preg_split('/[.,]/', $text) ?: [], 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
