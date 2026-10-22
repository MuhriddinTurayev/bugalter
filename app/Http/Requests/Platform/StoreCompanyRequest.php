<?php

namespace App\Http\Requests\Platform;

use App\Models\PlatformStaff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var PlatformStaff|null $staff */
        $staff = $this->user('platform');

        return (bool) $staff?->isSuperAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => Str::lower(trim((string) $this->input('slug'))), 'owner_email' => Str::lower(trim((string) $this->input('owner_email')))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'stir' => ['nullable', 'regex:/^\d{9}$/', Rule::unique('tenants', 'stir')],
            'slug' => ['required', 'string', 'max:40'],
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function attributes(): array
    {
        return collect(['name', 'stir', 'slug', 'plan_id', 'owner_name', 'owner_email', 'owner_phone'])
            ->mapWithKeys(fn (string $f) => [$f => __('platform.companies.fields.'.$f)])
            ->all();
    }

    public function messages(): array
    {
        return ['stir.regex' => __('ui.settings.errors.stir')];
    }
}
