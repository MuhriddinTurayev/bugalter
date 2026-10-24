@php
    use App\Enums\Permission;
    use App\Enums\TaxReportStatus as S;
    use App\Enums\TaxReportType;
    use App\Support\Money;

    $user = auth()->user();
    $canApprove = $user->hasPermission(Permission::ReportsApprove);
    $canSubmit = $user->hasPermission(Permission::ReportsSubmit);
    $summary = $check?->summary ?? [];
    // Read by the Windows agent in the accountant's browser: the vault key and the cabinet robot are not involved.
    $viaAgent = ($gatewayName ?? '') === 'desktop' || $check?->portal === 'desktop';
    $running = $latest?->isRunning() && $latest->started_at->gt(now()->subMinutes(15));
    $tones = ['error' => 'danger', 'warning' => 'warning', 'info' => 'info', 'ok' => 'success'];
    $icons = ['error' => 'x', 'warning' => 'warning', 'info' => 'eye', 'ok' => 'check'];
    $findingText = function (array $f) {
        $params = $f['params'] ?? [];
        if (isset($params['amount_minor'])) {
            $params['amount'] = Money::format($params['amount_minor']);
        }
        if (isset($params['cabinet'])) {
            $params['cabinet'] = __('tax.portal.kinds.'.$params['cabinet']);
        }
        if (isset($f['type'])) {
            $params['report'] = TaxReportType::from($f['type'])->label();
            $params['period'] = \App\Tax\TaxPeriod::fromStart($f['period_kind'], \Carbon\CarbonImmutable::parse($f['period_start']))->label();
            $params['date'] = isset($f['due_date']) ? \Carbon\CarbonImmutable::parse($f['due_date'])->format('d.m.Y') : '—';
        }

        return __('tax.portal.findings.'.$f['key'], array_filter($params, 'is_scalar'));
    };
@endphp

<x-layouts.app :title="__('tax.portal.title')">
    <x-ui.page-header :title="__('tax.portal.title')" :subtitle="__('tax.portal.subtitle')">
        <x-slot:actions>
            <x-ui.button :href="route('tax.index')" variant="secondary">{{ __('tax.back') }}</x-ui.button>
            @if ($canApprove)
                <form method="post" action="{{ route('tax.portal.check') }}">@csrf
                    <x-ui.button type="submit" icon="robot" :disabled="$running || ! $settings->portal_access || $configErrors !== [] || (! $hasKey && ! $viaAgent)">
                        {{ $running ? __('tax.portal.running') : __('tax.portal.check_now') }}
                    </x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- What is still missing before the robot may go in. --}}
    @if (! $settings->portal_access || $configErrors !== [] || (! $hasKey && ! $viaAgent) || ($portalName === 'sandbox' && ! $viaAgent))
        <div class="mb-5 space-y-2">
            @unless ($settings->portal_access)
                <div class="flex items-start gap-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
                    <x-ui.icon name="lock" class="mt-0.5 size-4 shrink-0" />
                    <p>{{ __('tax.portal.needs_access') }}
                        @can('autopilot.manage')<a href="{{ route('autopilot.show') }}" class="font-semibold underline">{{ __('tax.portal.open_autopilot') }}</a>@endcan</p>
                </div>
            @endunless
            @unless ($hasKey || $viaAgent)
                <div class="flex items-start gap-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
                    <x-ui.icon name="key" class="mt-0.5 size-4 shrink-0" />
                    <p>{{ __('tax.portal.errors.no_key') }}</p>
                </div>
            @endunless
            @foreach ($configErrors as $error)
                <div class="rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $error }}</div>
            @endforeach
            @if ($portalName === 'sandbox' && ! $viaAgent)
                <div class="rounded-2xl bg-soft px-4 py-3 text-sm text-muted ring-1 ring-line">{{ __('tax.portal.sandbox_note') }}</div>
            @endif
        </div>
    @endif

    @if ($check?->portal === 'desktop')
        <div class="mb-5 flex items-start gap-3 rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800 ring-1 ring-brand-100">
            <x-ui.icon name="robot" class="mt-0.5 size-4 shrink-0" />
            <p>{{ __('tax.portal.source_agent', ['time' => $check->finished_at->format('d.m.Y H:i')]) }}</p>
        </div>
    @endif

    @if ($running)
        <div class="mb-5 flex items-center gap-3 rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800 ring-1 ring-brand-100" x-data x-init="setTimeout(() => location.reload(), 5000)">
            <x-ui.icon name="robot" class="size-5 shrink-0 animate-pulse" />
            <p>{{ __('tax.portal.running_hint', ['time' => $latest->started_at->format('H:i')]) }}</p>
        </div>
    @elseif ($latest?->status === 'failed')
        <div class="mb-5 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">
            {{ __('tax.portal.failed', ['time' => $latest->finished_at?->format('d.m.Y H:i')]) }} {{ $latest->error }}
        </div>
    @endif

    @if ($check === null)
        <x-ui.card>
            <x-ui.empty-state :title="__('tax.portal.never_checked')" icon="robot" />
            <p class="mx-auto max-w-xl text-center text-sm text-muted">{{ __('tax.portal.how_it_works') }}</p>
        </x-ui.card>
    @else
        <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.card>
                <p class="label">{{ __('tax.portal.kpi.debt') }}</p>
                <p @class(['amount text-2xl font-bold', 'text-red-600' => ($summary['debt_minor'] ?? 0) > 0])>{{ Money::format(($summary['debt_minor'] ?? 0) + ($summary['penalty_minor'] ?? 0)) }}</p>
                @if (($summary['penalty_minor'] ?? 0) > 0)<p class="text-xs text-muted">{{ __('tax.portal.kpi.penalty', ['amount' => Money::format($summary['penalty_minor'])]) }}</p>@endif
            </x-ui.card>
            <x-ui.card>
                <p class="label">{{ __('tax.portal.kpi.overpaid') }}</p>
                <p class="amount text-2xl font-bold">{{ Money::format($summary['overpaid_minor'] ?? 0) }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="label">{{ __('tax.portal.kpi.missing') }}</p>
                <p @class(['text-2xl font-bold tabular-nums', 'text-red-600' => ($summary['overdue'] ?? 0) > 0])>{{ ($summary['missing'] ?? 0) + ($summary['overdue'] ?? 0) + ($summary['rejected'] ?? 0) }}</p>
                @if (($summary['overdue'] ?? 0) > 0)<p class="text-xs font-semibold text-red-600">{{ __('tax.portal.kpi.overdue', ['count' => $summary['overdue']]) }}</p>@endif
            </x-ui.card>
            <x-ui.card>
                <p class="label">{{ __('tax.portal.kpi.submitted') }}</p>
                <p class="text-2xl font-bold tabular-nums text-brand-700">{{ $summary['submitted'] ?? 0 }}</p>
            </x-ui.card>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            {{-- Step 3 of the flow: prepared reports waiting for "Submit?". --}}
            <x-ui.card padding="p-0">
                <div class="px-5 pt-5 sm:px-6">
                    <h2 class="font-semibold">{{ __('tax.portal.waiting_title') }}</h2>
                    <p class="text-xs text-muted">{{ __('tax.portal.waiting_hint') }}</p>
                </div>
                @if ($waiting->isEmpty())
                    <x-ui.empty-state :title="__('tax.portal.waiting_empty')" icon="check" />
                @else
                    <ul class="mt-3 divide-y divide-line">
                        @foreach ($waiting as $report)
                            <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:px-6">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('tax.reports.show', $report) }}" class="font-semibold hover:underline">{{ $report->title() }}</a>
                                    <p class="text-xs text-muted">
                                        {{ __('tax.due', ['date' => $report->due_date?->format('d.m.Y') ?? '—']) }}
                                        @if ($report->isOverdue())<span class="font-semibold text-red-600">· {{ __('tax.portal.overdue') }}</span>@endif
                                    </p>
                                    @if ($report->blockingIssues() !== [])
                                        <p class="text-xs text-red-600">{{ __('tax.fix_issues_first') }}</p>
                                    @elseif ($report->status === S::Blocked && $report->status_note)
                                        <p class="text-xs text-red-600">{{ $report->status_note }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 sm:justify-end">
                                    <x-ui.badge :tone="$report->status->tone()">{{ $report->status->label() }}</x-ui.badge>
                                    <span class="amount w-36 text-right font-semibold">{{ Money::format($report->payable_minor) }}</span>
                                    <div class="w-40">
                                        @if ($canApprove && $canSubmit)
                                            <x-tax.submit-dialog :report="$report" :gateway="$gatewayName" size="sm" />
                                        @else
                                            <x-ui.button size="sm" variant="soft" class="w-full" :href="route('tax.reports.show', $report)">{{ __('tax.open') }}</x-ui.button>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @if ($check)
                <x-ui.card>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="font-semibold">{{ __('tax.portal.findings_title') }}</h2>
                        <span class="text-xs text-muted">{{ __('tax.portal.checked_at', ['time' => $check->finished_at->format('d.m.Y H:i'), 'who' => $check->actor_name]) }}</span>
                    </div>
                    <ul class="space-y-2.5">
                        @foreach ($check->findings ?? [] as $finding)
                            <li class="flex items-start gap-2.5 text-sm">
                                <x-ui.icon :name="$icons[$finding['severity']]" :class="'mt-0.5 size-4 shrink-0 '.['error' => 'text-red-600', 'warning' => 'text-amber-600', 'info' => 'text-sky-600', 'ok' => 'text-brand-600'][$finding['severity']]" />
                                <span class="min-w-0 flex-1">
                                    {{ $findingText($finding) }}
                                    @if (! empty($finding['params']['local_status']) && in_array($finding['key'], ['missing', 'overdue'], true) && in_array($finding['params']['local_status'], ['submitted', 'accepted'], true))
                                        <span class="block text-xs text-amber-700">{{ __('tax.portal.local_sent_not_on_portal') }}</span>
                                    @endif
                                    @if (! empty($finding['params']['error']))
                                        <span class="block text-xs text-red-600">{{ $finding['params']['error'] }}</span>
                                    @endif
                                </span>
                                @if (! empty($finding['report_id']))
                                    <a href="{{ route('tax.reports.show', $finding['report_id']) }}" class="shrink-0 text-xs font-semibold text-brand-700 hover:underline">{{ __('tax.open') }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if (($summary['prepared'] ?? 0) > 0 || ($summary['requested'] ?? 0) > 0)
                        <p class="mt-4 rounded-2xl bg-brand-50 px-3 py-2 text-xs text-brand-800">{{ __('tax.portal.prepared_summary', ['prepared' => $summary['prepared'] ?? 0, 'requested' => $summary['requested'] ?? 0]) }}</p>
                    @endif
                </x-ui.card>

                <x-ui.card padding="p-0">
                    <h2 class="px-5 pt-5 font-semibold sm:px-6">{{ __('tax.portal.reports_title') }}</h2>
                    @if (empty($check->snapshot['reports']))
                        <p class="px-5 pb-5 pt-2 text-sm text-muted sm:px-6">{{ __('tax.portal.reports_empty') }}</p>
                    @else
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full min-w-[36rem]">
                                <thead><tr class="border-b border-line">
                                    <th class="th">{{ __('tax.report') }}</th>
                                    <th class="th">{{ __('tax.period') }}</th>
                                    <th class="th">{{ __('tax.submitted_at') }}</th>
                                    <th class="th">{{ __('ui.common.status') }}</th>
                                    <th class="th">{{ __('tax.receipt') }}</th>
                                </tr></thead>
                                <tbody class="divide-y divide-line">
                                    @foreach ($check->snapshot['reports'] as $row)
                                        <tr>
                                            <td class="td">{{ $row['type'] ? TaxReportType::from($row['type'])->label() : $row['name'] }}@if ($row['form'] !== '')<span class="block font-mono text-xs text-muted">{{ $row['form'] }}</span>@endif</td>
                                            <td class="td tabular-nums">{{ $row['period_start'] ? \Carbon\CarbonImmutable::parse($row['period_start'])->format('d.m.Y') : '—' }} – {{ $row['period_end'] ? \Carbon\CarbonImmutable::parse($row['period_end'])->format('d.m.Y') : '—' }}</td>
                                            <td class="td tabular-nums">{{ $row['submitted_at'] ? \Carbon\CarbonImmutable::parse($row['submitted_at'])->format('d.m.Y') : '—' }}</td>
                                            <td class="td"><x-ui.badge :tone="['accepted' => 'success', 'submitted' => 'info', 'rejected' => 'danger', 'draft' => 'neutral'][$row['status']]">{{ __('tax.portal.report_statuses.'.$row['status']) }}</x-ui.badge></td>
                                            <td class="td font-mono text-xs">{{ $row['receipt'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-5">
            @if ($check)
                <x-ui.card>
                    <h2 class="mb-3 font-semibold">{{ __('tax.portal.balances_title') }}</h2>
                    @forelse ($check->snapshot['balances'] ?? [] as $balance)
                        <div class="flex items-center justify-between gap-3 border-b border-line py-2 text-sm last:border-0">
                            <span class="min-w-0 truncate">{{ $balance['name'] !== '' ? $balance['name'] : __('tax.portal.taxes.'.$balance['tax']) }}</span>
                            <span class="text-right">
                                @if ($balance['debt_minor'] + $balance['penalty_minor'] > 0)
                                    <span class="amount block font-semibold text-red-600">−{{ Money::format($balance['debt_minor'] + $balance['penalty_minor']) }}</span>
                                @elseif ($balance['overpaid_minor'] > 0)
                                    <span class="amount block font-semibold text-brand-700">+{{ Money::format($balance['overpaid_minor']) }}</span>
                                @else
                                    <span class="amount block text-muted">{{ Money::format(0) }}</span>
                                @endif
                                @if (($balance['assessed_minor'] ?? 0) > 0)
                                    <span class="block text-xs text-muted">{{ __('tax.portal.assessed', ['amount' => Money::format($balance['assessed_minor'])]) }}</span>
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('tax.portal.balances_empty') }}</p>
                    @endforelse
                </x-ui.card>

                @if (! empty($check->snapshot['messages']))
                    <x-ui.card>
                        <h2 class="mb-3 font-semibold">{{ __('tax.portal.messages_title') }}</h2>
                        <ul class="space-y-2 text-sm">
                            @foreach (array_slice($check->snapshot['messages'], 0, 10) as $message)
                                <li class="flex items-start gap-2">
                                    <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-brand-600' => $message['unread'], 'bg-line' => ! $message['unread']])></span>
                                    <span class="min-w-0"><span @class(['block', 'font-semibold' => $message['unread']])>{{ $message['title'] }}</span>
                                        <span class="text-xs text-muted">{{ $message['date'] ? \Carbon\CarbonImmutable::parse($message['date'])->format('d.m.Y') : '' }}</span></span>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif
            @endif

            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.portal.history_title') }}</h2>
                @forelse ($history as $row)
                    <div class="flex items-center justify-between gap-3 py-1.5 text-sm">
                        <span class="min-w-0"><span class="block">{{ $row->started_at->format('d.m.Y H:i') }}</span><span class="text-xs text-muted">{{ $row->actor_name }}</span></span>
                        <x-ui.badge :tone="['completed' => 'success', 'failed' => 'danger', 'running' => 'info'][$row->status] ?? 'neutral'">{{ __('tax.portal.check_statuses.'.$row->status) }}</x-ui.badge>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('tax.portal.history_empty') }}</p>
                @endforelse
            </x-ui.card>

            <p class="text-xs text-muted">{{ __('tax.portal.disclaimer') }}</p>
        </div>
    </div>
</x-layouts.app>
