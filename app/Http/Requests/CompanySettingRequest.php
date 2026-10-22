<?php

namespace App\Http\Requests;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Http\FormRequest;

class CompanySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_form' => ['nullable', 'string', 'max:30'],
            'stir' => ['nullable', 'regex:/^\d{9}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'regex:/^\d{20}$/'],
            'mfo' => ['nullable', 'regex:/^\d{5}$/'],
            'director_name' => ['nullable', 'string', 'max:255'],
            'chief_accountant_name' => ['nullable', 'string', 'max:255'],
            'vat_payer' => ['boolean'],
            'vat_registration_code' => ['nullable', 'string', 'max:20'],
            'default_vat_percent' => ['nullable', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
        ];
    }

    public function attributes(): array
    {
        return collect([
            'name', 'legal_form', 'stir', 'address', 'phone', 'email', 'bank_name', 'bank_account', 'mfo',
            'director_name', 'chief_accountant_name', 'vat_registration_code', 'default_vat_percent',
        ])->mapWithKeys(fn (string $field) => [$field => __('ui.settings.fields.'.$field)])->all();
    }

    public function messages(): array
    {
        return [
            'stir.regex' => __('ui.settings.errors.stir'),
            'bank_account.regex' => __('ui.contractors.errors.bank_account'),
            'mfo.regex' => __('ui.contractors.errors.mfo'),
        ];
    }

    /** @return array<string, mixed> */
    public function companyData(): array
    {
        $data = $this->validated();
        $percent = str_replace(',', '.', (string) ($data['default_vat_percent'] ?? ''));

        unset($data['default_vat_percent']);

        $data['vat_payer'] = $this->boolean('vat_payer');
        $data['default_vat_rate_bp'] = $percent === ''
            ? 0
            : BigDecimal::of($percent)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

        return $data;
    }
}
