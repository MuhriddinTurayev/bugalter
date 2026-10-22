<?php

namespace App\Http\Requests\Platform;

use App\Models\Plan;
use App\Models\PlatformStaff;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    /** Limits a plan can carry; blank means unlimited. */
    public const LIMITS = ['max_users', 'max_documents_per_month', 'max_transactions_per_month'];

    public function authorize(): bool
    {
        /** @var PlatformStaff|null $staff */
        $staff = $this->user('platform');

        return (bool) $staff?->isSuperAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => Str::lower(trim((string) $this->input('key')))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $plan = $this->route('plan');

        return [
            'key' => ['required', 'regex:/^[a-z0-9_-]{2,40}$/', Rule::unique('plans', 'key')->ignore($plan instanceof Plan ? $plan->id : null)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['nullable', 'string'],
            'billing_period' => ['required', Rule::in(['month', 'year'])],
            'limits' => ['array'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('price') && Money::parseToMinor($this->input('price')) === null) {
                $validator->errors()->add('price', __('platform.plans.errors.price'));
            }
        });
    }

    public function attributes(): array
    {
        return collect(['key', 'name', 'description', 'price', 'billing_period', 'is_active'])
            ->mapWithKeys(fn (string $f) => [$f => __('platform.plans.fields.'.$f)])
            ->all();
    }

    /** @return array<string, mixed> */
    public function planData(): array
    {
        $data = $this->validated();

        $limits = collect(self::LIMITS)
            ->mapWithKeys(fn (string $key) => [$key => $this->input("limits.$key") === null || $this->input("limits.$key") === '' ? null : (int) $this->input("limits.$key")])
            ->filter(fn ($value) => $value !== null)
            ->all();

        return [
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_minor' => $this->filled('price') ? Money::parseToMinor($this->input('price')) : null,
            'billing_period' => $data['billing_period'],
            'limits' => $limits === [] ? null : $limits,
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
