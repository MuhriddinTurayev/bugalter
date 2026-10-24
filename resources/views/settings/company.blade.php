<x-layouts.app :title="__('ui.settings.company')">
    @include('settings._nav')

    <form method="post" action="{{ route('settings.company.update') }}" class="max-w-4xl space-y-5" x-data="{ vat: @js((bool) old('vat_payer', $company->vat_payer)) }">
        @csrf @method('PUT')

        @if ($company->is_demo)
            <div class="rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">{{ __('ui.settings.demo_notice') }}</div>
        @endif

        <x-ui.card>
            <h2 class="mb-4 font-semibold">{{ __('ui.settings.profile') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input class="sm:col-span-2" name="name" :label="__('ui.settings.fields.name')" :value="$company->name" required />
                <x-ui.input name="legal_form" :label="__('ui.settings.fields.legal_form')" :value="$company->legal_form" />
                <x-ui.input name="stir" :label="__('ui.settings.fields.stir')" :value="$company->stir" inputmode="numeric" maxlength="9" />
                <x-ui.input name="pinfl" :label="__('ui.settings.fields.pinfl')" :value="$company->pinfl" inputmode="numeric" maxlength="14" />
                <x-ui.input name="tax_region" :label="__('ui.settings.fields.tax_region')" :value="$company->tax_region" />
                <x-ui.input name="tax_district" :label="__('ui.settings.fields.tax_district')" :value="$company->tax_district" />
                <x-ui.input class="sm:col-span-2" name="address" :label="__('ui.settings.fields.address')" :value="$company->address" />
                <x-ui.input name="phone" type="tel" :label="__('ui.settings.fields.phone')" :value="$company->phone" />
                <x-ui.input name="email" type="email" :label="__('ui.settings.fields.email')" :value="$company->email" />
                <x-ui.input name="director_name" :label="__('ui.settings.fields.director_name')" :value="$company->director_name" />
                <x-ui.input name="chief_accountant_name" :label="__('ui.settings.fields.chief_accountant_name')" :value="$company->chief_accountant_name" />
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-4 font-semibold">{{ __('ui.contractors.bank') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.input name="bank_name" :label="__('ui.settings.fields.bank_name')" :value="$company->bank_name" />
                <x-ui.input name="bank_account" :label="__('ui.settings.fields.bank_account')" :value="$company->bank_account" inputmode="numeric" maxlength="20" />
                <x-ui.input name="mfo" :label="__('ui.settings.fields.mfo')" :value="$company->mfo" inputmode="numeric" maxlength="5" />
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-4 font-semibold">{{ __('ui.settings.vat') }}</h2>
            <input type="hidden" name="vat_payer" value="0">
            <label class="mb-4 flex items-center gap-2 text-sm font-medium">
                <input type="checkbox" name="vat_payer" value="1" x-model="vat" class="size-4 rounded border-line text-brand-600 focus:ring-brand-500">
                {{ __('ui.settings.fields.vat_payer') }}
            </label>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" x-show="vat" x-cloak>
                <x-ui.input name="vat_registration_code" :label="__('ui.settings.fields.vat_registration_code')" :value="$company->vat_registration_code" />
                <x-ui.input name="default_vat_percent" inputmode="decimal" :label="__('ui.settings.fields.default_vat_percent')" :value="rtrim(rtrim(number_format($company->default_vat_rate_bp / 100, 2, '.', ''), '0'), '.')" :hint="__('ui.settings.vat_hint')" />
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-1 font-semibold">{{ __('tax.profile.title') }}</h2>
            <p class="mb-4 text-xs text-muted">{{ __('tax.profile.hint') }}</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.select name="tax_regime" :label="__('tax.profile.regime')" :value="$company->regime()->value" required>
                    @foreach (\App\Enums\TaxRegime::cases() as $regime)
                        <option value="{{ $regime->value }}" @selected($company->regime() === $regime)>{{ $regime->label() }}</option>
                    @endforeach
                </x-ui.select>
                <div class="flex items-end">
                    <input type="hidden" name="is_budget_org" value="0">
                    <label class="flex items-center gap-2 pb-2.5 text-sm font-medium">
                        <input type="checkbox" name="is_budget_org" value="1" @checked(old('is_budget_org', $company->is_budget_org)) class="size-4 rounded border-line text-brand-600 focus:ring-brand-500">
                        {{ __('tax.profile.budget_org') }}
                    </label>
                </div>
            </div>
        </x-ui.card>

        <div class="flex justify-end"><x-ui.button type="submit">{{ __('ui.common.save') }}</x-ui.button></div>
    </form>

    @if ($plan)
        <x-ui.card class="mt-5 max-w-4xl">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('ui.limits.plan', ['plan' => $plan->name]) }}</h2>
                <span class="text-sm text-muted">
                    {{ $subscriptionEndsAt ? __('ui.limits.subscription_until', ['date' => $subscriptionEndsAt->format('d.m.Y')]) : __('ui.limits.no_end_date') }}
                </span>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach ($usage as $row)
                    <div class="rounded-2xl bg-soft p-4">
                        <p class="label">{{ $row['label'] }}</p>
                        <p class="text-lg font-semibold tabular-nums">{{ $row['used'] }} <span class="text-sm font-normal text-muted">/ {{ $row['limit'] ?? __('ui.limits.unlimited') }}</span></p>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif
</x-layouts.app>
