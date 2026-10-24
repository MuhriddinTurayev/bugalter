@php
    use App\Support\Money;
@endphp

<x-layouts.app :title="__('tax.title')">
    <x-ui.page-header :title="__('tax.title')" :subtitle="$settings->regime()->label().($settings->vat_payer ? ' · '.__('tax.vat_payer') : '')">
        <x-slot:actions>
            <x-ui.button :href="route('tax.runs.create')" icon="sparkles">{{ __('tax.workflow.title') }}</x-ui.button>
            <x-ui.button :href="route('tax.portal')" variant="secondary" icon="robot">{{ __('tax.portal.title') }}</x-ui.button>
            <x-ui.button :href="route('tax.payments')" variant="secondary" icon="wallet">{{ __('tax.payments.title') }}</x-ui.button>
            <x-ui.button :href="route('tax.agent')" variant="secondary" icon="link">{{ __('tax.agent.title') }}</x-ui.button>
            <x-ui.button :href="route('ai.index')" variant="secondary" icon="sparkles">{{ __('tax.ask_ai') }}</x-ui.button>
            @can('autopilot.manage')
                <x-ui.button :href="route('autopilot.show')" variant="dark" icon="shield">{{ __('tax.autopilot', ['level' => $settings->autopilot()->label()]) }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($rulesLoaded)
        <div class="mb-5 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ __('tax.rules_missing') }}</div>
    @elseif (! $rulesVerified)
        <div class="mb-5 flex items-start gap-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
            <x-ui.icon name="warning" class="mt-0.5 size-4 shrink-0" />
            <p>{{ __('tax.rules_unverified') }}</p>
        </div>
    @endunless

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <x-ui.card padding="p-0" class="xl:col-span-2">
            <div class="flex items-center justify-between px-5 pt-5 sm:px-6">
                <h2 class="font-semibold">{{ __('tax.calendar') }}</h2>
                <span class="text-xs text-muted">{{ __('tax.calendar_hint') }}</span>
            </div>
            @if ($calendar === [])
                <x-ui.empty-state :title="__('tax.calendar_empty')" icon="calendar" />
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[44rem]">
                        <thead><tr class="border-b border-line">
                            <th class="th">{{ __('tax.report') }}</th>
                            <th class="th">{{ __('tax.period') }}</th>
                            <th class="th">{{ __('tax.due_date') }}</th>
                            <th class="th">{{ __('ui.common.status') }}</th>
                            <th class="th text-right">{{ __('tax.payable') }}</th>
                            <th class="th"></th>
                        </tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($calendar as $row)
                                @php
                                    $report = $row['report'];
                                    $days = (int) today()->diffInDays($row['due_date'], false);
                                    $late = $days < 0 && ! ($report && $report->status->isFinal());
                                @endphp
                                <tr @class(['bg-red-50/40' => $late])>
                                    <td class="td font-semibold">{{ $row['type']->label() }}</td>
                                    <td class="td">{{ $row['period']->label() }}@unless ($row['closed'])<p class="text-xs text-muted">{{ __('tax.period_running') }}</p>@endunless</td>
                                    <td class="td tabular-nums">
                                        {{ $row['due_date']->format('d.m.Y') }}
                                        @if ($report === null || ! $report->status->isFinal())
                                            <p @class(['text-xs', 'text-red-600 font-semibold' => $late, 'text-amber-700' => ! $late && $days <= 7, 'text-muted' => ! $late && $days > 7])>
                                                {{ $late ? __('tax.days_late', ['days' => abs($days)]) : __('tax.days_left', ['days' => $days]) }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="td">
                                        @if ($report)
                                            <x-ui.badge :tone="$report->status->tone()">{{ $report->status->label() }}</x-ui.badge>
                                        @else
                                            <x-ui.badge>{{ __('tax.not_prepared') }}</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="td amount text-right font-semibold">{{ $report ? Money::format($report->payable_minor) : '—' }}</td>
                                    <td class="td text-right">
                                        @if ($report)
                                            <x-ui.button size="sm" variant="soft" :href="route('tax.reports.show', $report)">{{ __('tax.open') }}</x-ui.button>
                                        @else
                                            <form method="post" action="{{ route('tax.prepare') }}">@csrf
                                                <input type="hidden" name="type" value="{{ $row['type']->value }}">
                                                <input type="hidden" name="period_start" value="{{ $row['period']->start->toDateString() }}">
                                                <x-ui.button type="submit" size="sm" :variant="$row['closed'] ? 'primary' : 'ghost'" icon="calculator">{{ $row['closed'] ? __('tax.prepare') : __('tax.preview') }}</x-ui.button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <div class="space-y-5">
            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.prepare_other') }}</h2>
                <form method="post" action="{{ route('tax.prepare') }}" class="space-y-3">
                    @csrf
                    <x-ui.select name="type" :label="__('tax.report')" required>
                        @foreach (\App\Enums\TaxReportType::cases() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input name="period_start" type="date" :label="__('tax.period_start')" :value="now()->subMonth()->startOfMonth()->toDateString()" :hint="__('tax.period_start_hint')" required />
                    <x-ui.button type="submit" class="w-full" icon="calculator">{{ __('tax.calculate') }}</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.recent') }}</h2>
                @forelse ($reports as $report)
                    <a href="{{ route('tax.reports.show', $report) }}" class="-mx-2 flex items-center justify-between gap-3 rounded-2xl px-2 py-2 transition hover:bg-soft">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold">{{ $report->title() }}</span>
                            <span class="text-xs text-muted">{{ Money::format($report->payable_minor) }}</span>
                        </span>
                        <x-ui.badge :tone="$report->status->tone()">{{ $report->status->label() }}</x-ui.badge>
                    </a>
                @empty
                    <p class="text-sm text-muted">{{ __('tax.no_reports') }}</p>
                @endforelse
            </x-ui.card>

            <p class="text-xs text-muted">{{ __('tax.disclaimer') }}</p>
        </div>
    </div>
</x-layouts.app>
