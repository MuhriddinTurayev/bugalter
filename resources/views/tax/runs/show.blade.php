@php
    use App\Support\Money;
    use App\Tax\ReportChecklist;

    $month = __('ui.months_full.'.$run->month()->month).' '.$run->month()->year;
    $current = match (true) {
        $run->status !== 'completed' => 2,
        collect($rows)->contains(fn ($r) => in_array($r['status'], ['approved', 'in_progress', 'submitted', 'accepted', 'unknown'], true)) => 5,
        default => 3,
    };
    $owed = array_values(array_filter($rows, fn ($r) => $r['status'] !== 'not_required'));
    $notOwed = array_values(array_filter($rows, fn ($r) => $r['status'] === 'not_required'));
    $selectable = array_filter($owed, fn ($r) => $r['selectable']);
@endphp

<x-layouts.app :title="__('tax.workflow.title')">
    <x-ui.page-header :title="__('tax.workflow.title')" :subtitle="__('tax.workflow.run_subtitle', ['month' => $month, 'who' => $run->actor_name])">
        <x-slot:actions>
            <x-ui.button :href="route('tax.runs.create')" variant="secondary">{{ __('tax.workflow.new_run') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('tax.runs._stepper', ['current' => $current])

    {{-- Step 2: the real state of every step, polled while the run (or a submission) is moving. --}}
    <div x-data="{ steps: @js($status['steps']), fp: @js($status['fingerprint']) }"
         x-init="if (@js($status['active'])) { const timer = setInterval(async () => {
             const response = await fetch(@js(route('tax.runs.status', $run)), { headers: { Accept: 'application/json' } });
             if (! response.ok) return;
             const data = await response.json();
             steps = data.steps;
             if (data.fingerprint !== fp) { clearInterval(timer); location.reload(); }
             else if (! data.active) clearInterval(timer);
         }, 2500) }">
        <x-ui.card class="mb-5">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('tax.workflow.analysis') }}</h2>
                <x-ui.badge :tone="['completed' => 'success', 'failed' => 'danger'][$status['status']] ?? 'info'">{{ __('tax.workflow.run_statuses.'.$status['status']) }}</x-ui.badge>
            </div>
            <ol class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <template x-for="step in steps" :key="step.key">
                    <li class="flex items-start gap-3 rounded-2xl bg-soft/60 px-3 py-2.5">
                        <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full"
                              :class="{ 'bg-brand-100 text-brand-700': step.status === 'done', 'bg-amber-100 text-amber-700': step.status === 'warning', 'bg-red-100 text-red-700': step.status === 'failed', 'bg-white text-muted ring-1 ring-line': ['pending', 'skipped'].includes(step.status), 'bg-sky-100 text-sky-700': step.status === 'running' }">
                            <span x-show="step.status === 'running'" class="size-3 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                            <span x-show="step.status === 'done'"><x-ui.icon name="check" class="size-3.5" /></span>
                            <span x-show="step.status === 'warning'"><x-ui.icon name="warning" class="size-3.5" /></span>
                            <span x-show="step.status === 'failed'"><x-ui.icon name="x" class="size-3.5" /></span>
                            <span x-show="step.status === 'skipped'" class="text-xs font-bold">–</span>
                        </span>
                        <span class="min-w-0 text-sm">
                            <span class="block font-semibold" x-text="step.label"></span>
                            <span class="block text-xs text-muted" x-show="step.detail" x-text="step.detail"></span>
                            <span class="block text-xs text-muted" x-show="step.status === 'skipped' && ! step.detail">{{ __('tax.workflow.step_statuses.skipped') }}</span>
                        </span>
                    </li>
                </template>
            </ol>
            @if ($status['status'] === 'failed')
                <p class="mt-4 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $run->error ?: __('tax.workflow.errors.unexpected') }}</p>
            @endif
        </x-ui.card>
    </div>

    @if ($run->status === 'completed')
        {{-- Step 3: one row per report with its live state. Only "ready" rows can be selected. --}}
        <form method="get" action="{{ route('tax.runs.confirm', $run) }}">
            <x-ui.card padding="p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 sm:px-6">
                    <div>
                        <h2 class="font-semibold">{{ __('tax.workflow.review') }}</h2>
                        <p class="text-xs text-muted">{{ __('tax.workflow.review_hint') }}</p>
                    </div>
                    @if ($canSubmit && $selectable !== [])
                        <x-ui.button type="submit" icon="check">{{ __('tax.workflow.review_selected') }}</x-ui.button>
                    @endif
                </div>

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[60rem]">
                        <thead><tr class="border-b border-line">
                            <th class="th w-10"></th>
                            <th class="th">{{ __('tax.report') }}</th>
                            <th class="th">{{ __('tax.due_date') }}</th>
                            <th class="th text-right">{{ __('tax.tax') }}</th>
                            <th class="th text-right">{{ __('tax.payable') }}</th>
                            <th class="th">{{ __('tax.workflow.source_data') }}</th>
                            <th class="th">{{ __('tax.workflow.check') }}</th>
                            <th class="th">{{ __('tax.workflow.submission') }}</th>
                            <th class="th"></th>
                        </tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($owed as $row)
                                @php $report = $row['report']; @endphp
                                <tr class="align-top">
                                    <td class="td">
                                        @if ($canSubmit && $row['selectable'])
                                            <input type="checkbox" name="reports[]" value="{{ $report->id }}" checked class="size-4 rounded border-line text-brand-700 focus:ring-brand-500" aria-label="{{ $report->title() }}">
                                        @endif
                                    </td>
                                    <td class="td">
                                        <p class="font-semibold">{{ $row['type_enum']->label() }}</p>
                                        <p class="text-xs text-muted">{{ $report?->periodLabel() ?? ($row['period_start'] ? \App\Tax\TaxPeriod::fromStart($row['period_kind'], \Carbon\CarbonImmutable::parse($row['period_start']))->label() : '—') }}
                                            @unless ($row['closed'])· {{ __('tax.period_running') }}@endunless</p>
                                    </td>
                                    <td class="td tabular-nums">{{ $row['due_date'] ? \Carbon\CarbonImmutable::parse($row['due_date'])->format('d.m.Y') : '—' }}</td>
                                    <td class="td amount text-right">{{ $report ? Money::format($report->tax_minor) : '—' }}</td>
                                    <td class="td amount text-right font-semibold">{{ $report ? Money::format($report->payable_minor) : '—' }}</td>
                                    <td class="td text-xs text-muted">
                                        @if (! empty($row['source']))
                                            {{ __('tax.workflow.source_counts', ['documents' => $row['source']['documents'], 'transactions' => $row['source']['transactions']]) }}
                                            @if ($row['source']['unclassified'] > 0)<span class="block text-amber-700">{{ __('tax.workflow.unclassified', ['count' => $row['source']['unclassified']]) }}</span>@endif
                                        @else — @endif
                                    </td>
                                    <td class="td">
                                        <x-ui.badge :tone="ReportChecklist::tone($row['status'])">{{ __('tax.workflow.checklist.'.$row['status']) }}</x-ui.badge>
                                        @foreach ($row['errors'] as $error)
                                            <p class="mt-1 max-w-xs text-xs text-red-600">{{ $error }}</p>
                                        @endforeach
                                        @if ($report && $report->blockingIssues() !== [])
                                            <p class="mt-1 max-w-xs text-xs text-red-600">{{ __('tax.workflow.blocking_issues', ['count' => count($report->blockingIssues())]) }}</p>
                                        @endif
                                        @foreach ($row['warnings'] as $warning)
                                            <p class="mt-1 max-w-xs text-xs text-amber-700">{{ $warning }}</p>
                                        @endforeach
                                    </td>
                                    <td class="td">
                                        @if ($report)
                                            <x-ui.badge :tone="$report->status->tone()">{{ $report->status->label() }}</x-ui.badge>
                                            @if ($report->receipt_number)<p class="mt-1 font-mono text-xs">{{ $report->receipt_number }}</p>@endif
                                            @if (($report->authority_response['sandbox'] ?? false))<p class="mt-1 text-xs text-amber-700">{{ __('tax.workflow.sandbox_short') }}</p>@endif
                                        @else
                                            <x-ui.badge>{{ __('tax.not_prepared') }}</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="td text-right">
                                        @if ($report)<x-ui.button size="sm" variant="soft" :href="route('tax.reports.show', $report)">{{ __('tax.open') }}</x-ui.button>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($notOwed !== [])
                    <p class="border-t border-line px-5 py-3 text-xs text-muted sm:px-6">
                        {{ __('tax.workflow.not_required_list', ['reports' => implode(', ', array_map(fn ($r) => $r['type_enum']->label(), $notOwed))]) }}
                    </p>
                @endif
            </x-ui.card>

            @if (! $canSubmit && $selectable !== [])
                <p class="mt-3 text-xs text-muted">{{ __('tax.workflow.needs_permissions') }}</p>
            @endif
        </form>

        <p class="mt-4 text-xs text-muted">{{ __('tax.disclaimer') }}</p>
    @endif
</x-layouts.app>
