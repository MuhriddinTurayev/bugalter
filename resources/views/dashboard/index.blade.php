<x-layouts.app :title="__('ui.nav.home')">
    @php
        $company = app(\App\Services\CurrentCompany::class);
        $user = auth()->user();
        $js = fn ($value) => \Illuminate\Support\Js::from($value)->toHtml();
        $card = fn (string $name) => $js(route('dashboard.card', $name));
        $cards = [
            'balance' => 'simpleCard('.$card('balance').')',
            'weekly' => 'simpleCard('.$card('weekly').')',
            'payments' => 'simpleCard('.$card('payments').')',
            'receivables' => 'simpleCard('.$card('receivables').')',
            'trend' => 'trendCard('.$card('trend').', '.$js(['income' => __('ui.dashboard.trend.income'), 'expense' => __('ui.dashboard.trend.expense')]).')',
            'cashflow' => 'cashflowCard('.$card('cashflow').')',
        ];
    @endphp

    {{-- Greeting row --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4"
         x-data="rangePicker({ from: @js($defaultFrom), to: @js($defaultTo) })">
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
            {{ __('ui.dashboard.greeting') }}, <span class="text-muted/80">{{ $user->firstName() }}</span>
        </h1>

        <div class="flex flex-wrap items-center gap-2.5">
            <div class="relative" @keydown.escape.window="open = false">
                <button type="button" class="flex items-center gap-2.5 rounded-full bg-white px-4 py-2.5 text-sm font-semibold shadow-card transition hover:bg-soft"
                        @click="open = !open; draft = { from, to }" :aria-expanded="open">
                    <x-ui.icon name="calendar" class="size-[18px]" />
                    <span x-text="label" class="tabular-nums"></span>
                    <x-ui.icon name="chevron-down" class="size-4 text-muted" />
                </button>

                <div x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false"
                     class="absolute right-0 z-30 mt-2 w-[19rem] max-w-[calc(100vw-1.5rem)] rounded-3xl bg-white p-4 shadow-2xl ring-1 ring-line">
                    <p class="label">{{ __('ui.dashboard.presets') }}</p>
                    <div class="mb-4 flex flex-wrap gap-1.5">
                        <button type="button" class="rounded-full bg-soft px-3 py-1.5 text-xs font-semibold hover:bg-brand-50" @click="preset(1)">{{ __('ui.dashboard.this_month') }}</button>
                        <button type="button" class="rounded-full bg-soft px-3 py-1.5 text-xs font-semibold hover:bg-brand-50" @click="preset(3)">{{ __('ui.dashboard.last_3_months') }}</button>
                        <button type="button" class="rounded-full bg-soft px-3 py-1.5 text-xs font-semibold hover:bg-brand-50" @click="preset(6)">{{ __('ui.dashboard.last_6_months') }}</button>
                        <button type="button" class="rounded-full bg-soft px-3 py-1.5 text-xs font-semibold hover:bg-brand-50" @click="thisYear()">{{ __('ui.dashboard.this_year') }}</button>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="range-from">{{ __('ui.common.from') }}</label>
                            <input id="range-from" type="date" class="field" x-model="draft.from" :max="draft.to">
                        </div>
                        <div>
                            <label class="label" for="range-to">{{ __('ui.common.to') }}</label>
                            <input id="range-to" type="date" class="field" x-model="draft.to" :min="draft.from">
                        </div>
                    </div>
                    <x-ui.button class="mt-4 w-full" @click="apply()">{{ __('ui.common.apply') }}</x-ui.button>
                </div>
            </div>

            @can('create', \App\Models\Document::class)
                <a href="{{ route('documents.create') }}" class="flex items-center gap-2 rounded-full bg-white px-4 py-2.5 text-sm font-semibold shadow-card transition hover:bg-soft">
                    <x-ui.icon name="plus" class="size-[18px]" /> {{ __('ui.dashboard.new_document') }}
                </a>
            @endcan
        </div>
    </div>

    {{-- grid-cols-1 (minmax(0, 1fr)): on a phone, wide content must not stretch the column past the screen. --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">

        {{-- Balance + weekly revenue --}}
        <div class="space-y-5 lg:col-span-3">
            <x-ui.card class="relative" x-data="{{ $cards['balance'] }}">
                <div class="mb-4 flex items-start justify-between">
                    <div>
                        <p class="text-sm font-semibold">{{ __('ui.dashboard.balance.title') }}</p>
                        <p class="text-xs text-muted">{{ __('ui.dashboard.balance.subtitle') }}</p>
                    </div>
                    <a href="{{ route('bank.index') }}" class="grid size-9 place-items-center rounded-full bg-soft text-ink transition hover:bg-brand-50" aria-label="{{ __('ui.nav.bank') }}"><x-ui.icon name="arrow-up-right" class="size-4" /></a>
                </div>

                <x-dashboard.state skeleton="h-44" empty="data.accounts.length === 0" :empty-title="__('ui.dashboard.balance.empty')" empty-icon="wallet">
                    <div class="rounded-3xl bg-gradient-to-br from-brand-500 to-brand-700 p-5 text-white shadow-lg shadow-brand-700/25">
                        <div class="flex items-center justify-between text-xs text-white/80">
                            <span class="font-semibold uppercase tracking-wider">{{ __('ui.dashboard.balance.all_accounts') }}</span>
                            <x-ui.icon name="wallet" class="size-5" />
                        </div>
                        <p class="mt-5 text-3xl font-semibold tracking-tight tabular-nums">
                            <span x-text="data.total_label"></span>
                        </p>
                        <p class="text-xs text-white/75" x-text="data.currency"></p>
                        <ul class="mt-4 space-y-1.5 border-t border-white/20 pt-3 text-xs">
                            <template x-for="account in data.accounts" :key="account.id">
                                <li class="flex items-center justify-between gap-3">
                                    <span class="truncate text-white/85" x-text="account.name"></span>
                                    <span class="whitespace-nowrap font-semibold tabular-nums" x-text="account.balance_label"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </x-dashboard.state>
            </x-ui.card>

            <x-ui.card x-data="{{ $cards['weekly'] }}" padding="p-5">
                <p class="text-xs font-semibold text-muted">{{ __('ui.dashboard.weekly.title') }}</p>
                <x-dashboard.state skeleton="h-10">
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-2xl font-semibold tracking-tight tabular-nums" x-text="data.amount_label"></p>
                        <template x-if="data.change_percent !== null">
                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold"
                                  :class="data.change_percent >= 0 ? 'bg-brand-100 text-brand-700' : 'bg-red-100 text-red-700'"
                                  x-text="(data.change_percent >= 0 ? '+' : '−') + Math.abs(data.change_percent).toString().replace('.', ',') + '%'"></span>
                        </template>
                    </div>
                    <p class="mt-1 text-xs text-muted">{{ __('ui.dashboard.weekly.hint') }}</p>
                </x-dashboard.state>
            </x-ui.card>
        </div>

        {{-- Income vs expense --}}
        <x-ui.card class="lg:col-span-5"
                   x-data="{{ $cards['trend'] }}">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="grid size-9 place-items-center rounded-full bg-soft"><x-ui.icon name="chart" class="size-[18px]" /></span>
                    <p class="text-sm font-semibold">{{ __('ui.dashboard.trend.title') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex rounded-full bg-soft p-1 text-xs font-semibold" role="group" aria-label="{{ __('ui.dashboard.trend.title') }}">
                        <button type="button" class="rounded-full px-3.5 py-1.5 transition" :class="mode === 'monthly' ? 'bg-brand-600 text-white' : 'text-muted'" @click="setMode('monthly')">{{ __('ui.dashboard.trend.monthly') }}</button>
                        <button type="button" class="rounded-full px-3.5 py-1.5 transition" :class="mode === 'annual' ? 'bg-brand-600 text-white' : 'text-muted'" @click="setMode('annual')">{{ __('ui.dashboard.trend.annual') }}</button>
                    </div>
                </div>
            </div>

            <x-dashboard.state skeleton="h-64" empty="!data.has_data" :empty-title="__('ui.dashboard.trend.empty')" :empty-text="__('ui.dashboard.trend.empty_text')">
                <div class="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-xs">
                    <span class="flex items-center gap-2"><i class="size-2.5 rounded-full bg-brand-600"></i><span class="text-muted">{{ __('ui.dashboard.trend.income') }}</span> <b class="tabular-nums" x-text="data.income_total_label"></b></span>
                    <span class="flex items-center gap-2"><i class="size-2.5 rounded-full bg-[#d9dee3]"></i><span class="text-muted">{{ __('ui.dashboard.trend.expense') }}</span> <b class="tabular-nums" x-text="data.expense_total_label"></b></span>
                </div>
                <div class="h-60"><canvas x-ref="canvas" role="img" aria-label="{{ __('ui.dashboard.trend.title') }}"></canvas></div>
            </x-dashboard.state>
        </x-ui.card>

        {{-- Cash flow --}}
        <x-ui.card class="lg:col-span-4 overflow-hidden" padding="pt-5 sm:pt-6 px-0 pb-0"
                   x-data="{{ $cards['cashflow'] }}">
            <div class="px-5 sm:px-6">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm font-semibold">{{ __('ui.dashboard.cashflow.title') }}</p>
                        <p class="text-xs text-muted">{{ __('ui.dashboard.cashflow.subtitle') }}</p>
                    </div>
                    <a href="{{ route('history') }}" class="grid size-9 place-items-center rounded-full bg-soft transition hover:bg-brand-50" aria-label="{{ __('ui.nav.history') }}"><x-ui.icon name="arrow-up-right" class="size-4" /></a>
                </div>
                <x-dashboard.state skeleton="h-52" empty="!data.has_data" :empty-title="__('ui.dashboard.cashflow.empty')" empty-icon="chart">
                    <p class="mt-4 text-xs text-muted">{{ __('ui.dashboard.cashflow.total') }}</p>
                    <p class="text-3xl font-semibold tracking-tight tabular-nums"><span x-text="data.total_label"></span> <span class="text-base text-muted" x-text="data.currency"></span></p>
                    <div class="-mx-5 mt-3 h-64 sm:-mx-6"><canvas x-ref="canvas" role="img" aria-label="{{ __('ui.dashboard.cashflow.title') }}"></canvas></div>
                </x-dashboard.state>
            </div>
            <div class="relative z-10 -mt-1 flex justify-center pb-5 pt-3">
                <div class="flex rounded-full bg-white p-1 text-xs font-semibold shadow-card ring-1 ring-line" role="group">
                    <button type="button" class="flex items-center gap-1.5 rounded-full px-4 py-2 transition" :class="series === 'income' ? 'bg-brand-600 text-white' : 'text-muted'" @click="setSeries('income')">{{ __('ui.dashboard.cashflow.income') }} <x-ui.icon name="arrow-down" class="size-3.5" /></button>
                    <button type="button" class="flex items-center gap-1.5 rounded-full px-4 py-2 transition" :class="series === 'expense' ? 'bg-brand-600 text-white' : 'text-muted'" @click="setSeries('expense')">{{ __('ui.dashboard.cashflow.expense') }} <x-ui.icon name="arrow-up" class="size-3.5" /></button>
                </div>
            </div>
        </x-ui.card>

        {{-- Payment history --}}
        <x-ui.card class="lg:col-span-8" x-data="{{ $cards['payments'] }}">
            <div class="mb-3 flex items-start justify-between">
                <div>
                    <p class="text-sm font-semibold">{{ __('ui.dashboard.payments.title') }}</p>
                    <p class="text-xs text-muted">{{ __('ui.dashboard.payments.subtitle') }}</p>
                </div>
                <a href="{{ route('history') }}" class="grid size-9 place-items-center rounded-full bg-soft transition hover:bg-brand-50" aria-label="{{ __('ui.nav.history') }}"><x-ui.icon name="arrow-up-right" class="size-4" /></a>
            </div>

            <x-dashboard.state skeleton="h-48" empty="!data.has_data" :empty-title="__('ui.dashboard.payments.empty')" empty-icon="receipt">
                <div class="hidden grid-cols-[2.2fr_1fr_.7fr_1.1fr_1.3fr] gap-3 px-2 pb-2 text-xs font-semibold text-muted md:grid">
                    <span>{{ __('ui.dashboard.payments.name') }}</span><span>{{ __('ui.dashboard.payments.date') }}</span><span>{{ __('ui.dashboard.payments.time') }}</span><span>{{ __('ui.dashboard.payments.status') }}</span><span class="text-right">{{ __('ui.dashboard.payments.amount') }}</span>
                </div>
                <ul class="space-y-1">
                    <template x-for="row in data.rows" :key="row.id">
                        <li class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1 rounded-2xl px-2 py-2.5 odd:bg-transparent even:bg-soft/70 md:grid-cols-[2.2fr_1fr_.7fr_1.1fr_1.3fr]">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-bold text-brand-700" x-text="row.initial"></span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold" x-text="row.title"></p>
                                    <p class="truncate text-xs" :class="row.type === 'income' ? 'text-brand-600' : 'text-muted'" x-text="row.subtitle"></p>
                                </div>
                            </div>
                            <span class="hidden text-sm tabular-nums md:block" x-text="row.date"></span>
                            <span class="hidden text-sm tabular-nums md:block" x-text="row.time"></span>
                            <span class="hidden items-center gap-1.5 text-sm md:flex">
                                <i class="size-1.5 rounded-full" :class="row.status === 'completed' ? 'bg-brand-500' : 'bg-amber-500'"></i>
                                <span x-text="row.status_label"></span>
                            </span>
                            <span class="amount text-right text-sm font-semibold" :class="row.type === 'income' ? 'text-brand-700' : 'text-ink'" x-text="row.amount_label"></span>
                            <span class="col-span-2 -mt-0.5 pl-12 text-xs text-muted md:hidden" x-text="row.date + ' · ' + row.time + ' · ' + row.status_label"></span>
                        </li>
                    </template>
                </ul>
            </x-dashboard.state>
        </x-ui.card>

        {{-- Receivables / payables --}}
        <x-ui.card class="lg:col-span-4" x-data="{{ $cards['receivables'] }}">
            <div class="mb-3 flex items-start justify-between">
                <div>
                    <p class="text-sm font-semibold">{{ __('ui.dashboard.receivables.title') }}</p>
                    <p class="text-xs text-muted">{{ __('ui.dashboard.receivables.subtitle') }}</p>
                </div>
                <a href="{{ route('documents.index', ['status' => 'sent']) }}" class="grid size-9 place-items-center rounded-full bg-soft transition hover:bg-brand-50" aria-label="{{ __('ui.nav.documents') }}"><x-ui.icon name="arrow-up-right" class="size-4" /></a>
            </div>

            <x-dashboard.state skeleton="h-48" empty="!data.has_data" :empty-title="__('ui.dashboard.receivables.empty')" empty-icon="document">
                <p class="text-xs text-muted">{{ __('ui.dashboard.receivables.receivable') }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-2xl font-semibold tracking-tight tabular-nums" x-text="data.receivable_label"></p>
                    <span class="rounded-full bg-brand-100 px-2.5 py-1 text-xs font-bold text-brand-700" x-text="data.receivable_count + ' {{ __('ui.dashboard.receivables.docs') }}'"></span>
                </div>
                <p class="mt-4 text-xs text-muted">{{ __('ui.dashboard.receivables.payable') }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-xl font-semibold tracking-tight tabular-nums" x-text="data.payable_label"></p>
                    <span class="rounded-full bg-soft px-2.5 py-1 text-xs font-bold text-muted" x-text="data.payable_count + ' {{ __('ui.dashboard.receivables.docs') }}'"></span>
                </div>

                <div class="mt-4 rounded-2xl bg-soft p-3.5">
                    <p class="text-xs font-semibold">{{ __('ui.dashboard.receivables.overdue') }}</p>
                    <template x-if="data.overdue_count === 0">
                        <p class="mt-1 text-xs text-muted">{{ __('ui.dashboard.receivables.no_overdue') }}</p>
                    </template>
                    <template x-if="data.overdue_count > 0">
                        <div class="mt-2 flex items-center justify-between gap-3">
                            <div class="flex -space-x-2">
                                <template x-for="person in data.overdue_people" :key="person.name">
                                    <span class="grid size-9 place-items-center rounded-full bg-amber-100 text-xs font-bold text-amber-700 ring-2 ring-soft" :title="person.name" x-text="person.initial"></span>
                                </template>
                                <template x-if="data.overdue_extra > 0">
                                    <span class="grid size-9 place-items-center rounded-full bg-brand-600 text-xs font-bold text-white ring-2 ring-soft" x-text="'+' + data.overdue_extra"></span>
                                </template>
                            </div>
                            <p class="text-right text-xs"><b class="tabular-nums" x-text="data.overdue_total_label"></b><br><span class="text-muted" x-text="data.overdue_count + ' {{ __('ui.dashboard.receivables.docs') }}'"></span></p>
                        </div>
                    </template>
                </div>
            </x-dashboard.state>
        </x-ui.card>

        {{-- Tax calendar: the next reports the company owes --}}
        <x-ui.card class="lg:col-span-6">
            <div class="flex items-start gap-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-full bg-soft text-muted"><x-ui.icon name="calendar" class="size-5" /></span>
                <div class="min-w-0 grow">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold">{{ __('ui.dashboard.tax.title') }}</p>
                        @can('view-reports')<a href="{{ route('tax.index') }}" class="text-xs font-semibold text-brand-700 hover:underline">{{ __('ui.dashboard.tax.open') }}</a>@endcan
                    </div>
                    @forelse ($upcomingTaxes as $row)
                        @php $days = (int) today()->diffInDays($row['due_date'], false); @endphp
                        <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate">{{ $row['type']->label() }} · {{ $row['period']->label() }}</span>
                            <span @class(['shrink-0 tabular-nums text-xs font-semibold', 'text-red-600' => $days < 0, 'text-amber-700' => $days >= 0 && $days <= 7, 'text-muted' => $days > 7])>
                                {{ $row['due_date']->format('d.m.Y') }}
                                @if ($row['report'])<x-ui.badge :tone="$row['report']->status->tone()" class="ml-1">{{ $row['report']->status->label() }}</x-ui.badge>@endif
                            </span>
                        </div>
                    @empty
                        <p class="mt-1 text-sm text-muted">{{ __('ui.dashboard.tax.body') }}</p>
                    @endforelse
                </div>
            </div>
        </x-ui.card>

        {{-- AI accountant --}}
        <x-ui.card class="lg:col-span-6">
            <div class="flex items-start gap-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600"><x-ui.icon name="sparkles" class="size-5" /></span>
                <div class="min-w-0 grow">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-semibold">{{ __('ui.dashboard.ai.title') }}</p>
                        <x-ui.badge :tone="$aiProvider ? 'success' : 'warning'">{{ $aiProvider ?? __('ui.dashboard.ai.not_configured') }}</x-ui.badge>
                    </div>
                    <p class="mt-1 text-sm text-muted">{{ __('ui.dashboard.ai.body') }}</p>
                    <x-ui.button :href="route('ai.index')" variant="soft" size="sm" icon="sparkles" class="mt-3">{{ __('ui.dashboard.ai.button') }}</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
