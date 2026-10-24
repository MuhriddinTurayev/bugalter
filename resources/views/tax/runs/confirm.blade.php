@php
    use App\Support\Money;

    $month = __('ui.months_full.'.$run->month()->month).' '.$run->month()->year;
@endphp

<x-layouts.app :title="__('tax.workflow.confirm_title')">
    <x-ui.page-header :title="__('tax.workflow.confirm_title')" :subtitle="__('tax.workflow.confirm_subtitle')">
        <x-slot:actions>
            <x-ui.button :href="route('tax.runs.show', $run)" variant="secondary">{{ __('tax.workflow.back_to_review') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('tax.runs._stepper', ['current' => 4])

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card padding="p-0">
                <div class="px-5 pt-5 sm:px-6">
                    <h2 class="font-semibold">{{ __('tax.workflow.selected_reports') }}</h2>
                    <p class="text-xs text-muted">{{ __('tax.workflow.version_hint') }}</p>
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[40rem]">
                        <thead><tr class="border-b border-line">
                            <th class="th">{{ __('tax.report') }}</th>
                            <th class="th">{{ __('tax.period') }}</th>
                            <th class="th">{{ __('tax.version') }}</th>
                            <th class="th text-right">{{ __('tax.base') }}</th>
                            <th class="th text-right">{{ __('tax.tax') }}</th>
                            <th class="th text-right">{{ __('tax.payable') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($rows as $row)
                                @php $report = $row['report']; @endphp
                                <tr class="align-top">
                                    <td class="td">
                                        <a href="{{ route('tax.reports.show', $report) }}" class="font-semibold hover:underline">{{ $report->type->label() }}</a>
                                        @foreach ($row['warnings'] as $warning)
                                            <p class="mt-1 max-w-xs text-xs text-amber-700">{{ $warning }}</p>
                                        @endforeach
                                    </td>
                                    <td class="td">{{ $report->periodLabel() }}
                                        <p class="text-xs text-muted tabular-nums">{{ $report->period_start->format('d.m.Y') }} – {{ $report->period_end->format('d.m.Y') }}</p></td>
                                    <td class="td font-mono text-xs">{{ substr($report->version_hash, 0, 12) }}</td>
                                    <td class="td amount text-right">{{ Money::format($report->base_minor) }}</td>
                                    <td class="td amount text-right">{{ Money::format($report->tax_minor) }}</td>
                                    <td class="td amount text-right font-semibold">{{ Money::format($report->payable_minor) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr class="border-t border-line bg-soft/60">
                            <td class="td font-semibold" colspan="5">{{ __('tax.workflow.total_payable') }}</td>
                            <td class="td amount text-right text-lg font-bold text-brand-700">{{ Money::format($total) }}</td>
                        </tr></tfoot>
                    </table>
                </div>
            </x-ui.card>

            @php $warnings = collect($rows)->flatMap(fn ($r) => $r['warnings'])->unique()->values(); @endphp
            @if ($warnings->isNotEmpty())
                <div class="flex items-start gap-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
                    <x-ui.icon name="warning" class="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p class="font-semibold">{{ __('tax.workflow.unresolved_warnings') }}</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-4">
                            @foreach ($warnings as $warning)<li>{{ $warning }}</li>@endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-5">
            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.workflow.company') }}</h2>
                <dl class="space-y-3 text-sm">
                    <div><dt class="label">{{ __('tax.workflow.company_name') }}</dt><dd class="font-semibold">{{ $settings->name }}</dd></div>
                    <div><dt class="label">{{ __('tax.workflow.tin') }}</dt><dd class="font-mono">{{ $settings->stir ?: '—' }}</dd></div>
                    <div><dt class="label">{{ __('tax.workflow.month') }}</dt><dd>{{ $month }}</dd></div>
                    <div><dt class="label">{{ __('tax.workflow.source.gateway') }}</dt><dd>{{ __('tax.submit_dialog.targets.'.(in_array($gateway, ['portal', 'http', 'sandbox'], true) ? $gateway : 'http')) }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <form method="post" action="{{ route('tax.runs.approve', $run) }}" class="space-y-4" x-data="{ agreed: false, sending: false }" x-on:submit="sending = true">
                    @csrf
                    @foreach ($rows as $row)
                        <input type="hidden" name="versions[{{ $row['report']->id }}]" value="{{ $row['report']->version_hash }}">
                    @endforeach

                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="declaration" value="1" x-model="agreed" class="mt-0.5 size-4 shrink-0 rounded border-line text-brand-700 focus:ring-brand-500" required>
                        <span>{{ __('tax.workflow.declaration', ['count' => count($rows), 'company' => $settings->name, 'month' => $month]) }}</span>
                    </label>
                    @error('declaration')<p class="text-xs font-medium text-red-600">{{ $message }}</p>@enderror

                    <ul class="space-y-1.5 text-xs text-muted">
                        <li class="flex gap-2"><x-ui.icon name="shield" class="size-4 shrink-0 text-brand-600" />{{ __('tax.submit_dialog.gates') }}</li>
                        <li class="flex gap-2"><x-ui.icon name="lock" class="size-4 shrink-0 text-brand-600" />{{ __('tax.workflow.version_bound') }}</li>
                    </ul>

                    <x-ui.button type="submit" class="w-full" icon="send" ::disabled="! agreed || sending">
                        <span x-show="! sending">{{ __('tax.workflow.approve_and_submit') }}</span>
                        <span x-show="sending" x-cloak>{{ __('tax.submit_dialog.sending') }}</span>
                    </x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
</x-layouts.app>
