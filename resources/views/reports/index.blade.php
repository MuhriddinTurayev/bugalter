@php use App\Support\Money; @endphp

<x-layouts.app :title="__('ui.reports.title')">
    <x-ui.page-header :title="__('ui.reports.title')" :subtitle="__('ui.reports.subtitle')" />

    {{-- Tax figures are never shown without loaded rules --}}
    <x-ui.card class="mb-5">
        <div class="flex items-start gap-4">
            <span class="grid size-11 shrink-0 place-items-center rounded-full {{ $taxRulesLoaded ? 'bg-brand-50 text-brand-600' : 'bg-amber-50 text-amber-600' }}"><x-ui.icon :name="$taxRulesLoaded ? 'check' : 'warning'" class="size-5" /></span>
            <div class="grow">
                <p class="font-semibold">{{ $taxRulesLoaded ? __('ui.reports.tax_ready_title') : __('ui.reports.tax_missing_title') }}</p>
                <p class="mt-1 text-sm text-muted">{{ $taxRulesLoaded ? __('ui.reports.tax_ready_body') : __('ui.reports.tax_missing_body') }}</p>
            </div>
            @if ($taxRulesLoaded)
                <x-ui.button :href="route('tax.index')" icon="calculator">{{ __('ui.nav.tax') }}</x-ui.button>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card class="mb-5">
        <form method="get" class="flex flex-wrap items-end gap-3">
            <div><label class="label" for="from">{{ __('ui.common.from') }}</label><input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="field"></div>
            <div><label class="label" for="to">{{ __('ui.common.to') }}</label><input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="field"></div>
            <x-ui.button type="submit" variant="dark">{{ __('ui.common.apply') }}</x-ui.button>
        </form>
    </x-ui.card>

    <div class="mb-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
        <x-ui.card padding="p-5"><p class="text-xs font-semibold text-muted">{{ __('ui.reports.income') }}</p><p class="amount mt-1 text-2xl font-semibold text-brand-700">{{ $income }}</p></x-ui.card>
        <x-ui.card padding="p-5"><p class="text-xs font-semibold text-muted">{{ __('ui.reports.expense') }}</p><p class="amount mt-1 text-2xl font-semibold">{{ $expense }}</p></x-ui.card>
        <x-ui.card padding="p-5"><p class="text-xs font-semibold text-muted">{{ __('ui.reports.net') }}</p><p class="amount mt-1 text-2xl font-semibold">{{ $net }}</p></x-ui.card>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        @foreach ([[__('ui.reports.income_by_category'), $incomeRows], [__('ui.reports.expense_by_category'), $expenseRows]] as [$heading, $rows])
            <x-ui.card padding="p-0">
                <h2 class="border-b border-line px-5 py-4 font-semibold">{{ $heading }}</h2>
                @if ($rows->isEmpty())
                    <x-ui.empty-state :title="__('ui.reports.empty')" icon="chart" class="py-8" />
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($rows as $category => $amount)
                            <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm"><span class="truncate">{{ $category }}</span><span class="amount font-semibold">{{ Money::format($amount) }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endforeach
    </div>
    <p class="mt-4 text-xs text-muted">{{ __('ui.reports.disclaimer') }}</p>
</x-layouts.app>
