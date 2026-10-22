@php
    $editing = $plan->exists;
    $price = $plan->price_minor === null ? '' : \App\Support\Money::toInputString($plan->price_minor);
@endphp

<x-layouts.platform :title="$editing ? __('platform.plans.edit') : __('platform.plans.new')">
    <x-ui.page-header :title="$editing ? __('platform.plans.edit') : __('platform.plans.new')">
        <x-slot:actions><x-ui.button :href="platform_route('plans.index')" variant="secondary">{{ __('platform.common.back') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <form method="post" action="{{ $editing ? platform_route('plans.update', ['plan' => $plan->id]) : platform_route('plans.store') }}" class="max-w-3xl space-y-5">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" :label="__('platform.plans.fields.name')" :value="$plan->name" required />
                <x-ui.input name="key" :label="__('platform.plans.fields.key')" :value="$plan->key" :hint="__('platform.plans.key_hint')" required />
                <x-ui.textarea class="sm:col-span-2" name="description" :label="__('platform.plans.fields.description')" :value="$plan->description" rows="2" />
                <x-ui.input name="price" inputmode="decimal" :label="__('platform.plans.fields.price').' ('.__('ui.currency.uzs').')'" :value="$price" :hint="__('platform.plans.price_hint')" />
                <x-ui.select name="billing_period" :label="__('platform.plans.fields.billing_period')" :value="$plan->billing_period" required>
                    <option value="month" @selected(old('billing_period', $plan->billing_period) === 'month')>{{ __('platform.plans.periods.month') }}</option>
                    <option value="year" @selected(old('billing_period', $plan->billing_period) === 'year')>{{ __('platform.plans.periods.year') }}</option>
                </x-ui.select>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-1 font-semibold">{{ __('platform.plans.limits_title') }}</h2>
            <p class="mb-4 text-sm text-muted">{{ __('platform.plans.limits_hint') }}</p>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach (\App\Http\Requests\Platform\PlanRequest::LIMITS as $limit)
                    <x-ui.input :name="'limits['.$limit.']'" inputmode="numeric" :label="__('platform.plans.limits.'.$limit)" :value="$plan->limit($limit)" :placeholder="__('platform.plans.unlimited')" />
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active)) class="size-4 rounded border-line text-brand-600 focus:ring-brand-500"> {{ __('platform.plans.fields.is_active') }}</label>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="platform_route('plans.index')" variant="ghost">{{ __('platform.common.cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="dark">{{ __('platform.common.save') }}</x-ui.button>
        </div>
    </form>
</x-layouts.platform>
