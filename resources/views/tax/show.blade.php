@php
    use App\Enums\Permission;
    use App\Enums\TaxReportStatus as S;
    use App\Support\Money;

    $user = auth()->user();
    $canApprove = $user->hasPermission(Permission::ReportsApprove);
    $canSubmit = $user->hasPermission(Permission::ReportsSubmit);
    $errors_ = $report->blockingIssues();
@endphp

<x-layouts.app :title="$report->title()">
    <x-ui.page-header :title="$report->title()" :subtitle="__('tax.due', ['date' => $report->due_date?->format('d.m.Y') ?? '—'])">
        <x-slot:actions>
            <x-ui.badge :tone="$report->status->tone()" class="!px-3.5 !py-1.5 !text-sm">{{ $report->status->label() }}</x-ui.badge>
            <x-ui.button :href="route('tax.index')" variant="secondary">{{ __('tax.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($report->status_note)
        <div class="mb-5 rounded-2xl bg-soft px-4 py-3 text-sm ring-1 ring-line">{{ $report->status_note }}</div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card padding="p-0">
                <div class="px-5 pt-5 sm:px-6">
                    <h2 class="font-semibold">{{ __('tax.calculation') }}</h2>
                    <p class="text-xs text-muted">{{ __('tax.calculation_hint', ['who' => $report->prepared_by_name ?? '—', 'when' => $report->prepared_at?->format('d.m.Y H:i') ?? '—']) }}</p>
                </div>
                <table class="mt-3 w-full">
                    <tbody class="divide-y divide-line">
                        @foreach ($report->lines ?? [] as $line)
                            <tr @class(['bg-soft/60' => $line['total'] ?? false])>
                                <td @class(['td', 'font-semibold' => $line['total'] ?? false])>{{ trans_choice('tax.lines.'.$line['key'], $line['params']['count'] ?? 1, $line['params'] ?? []) }}</td>
                                <td @class(['td amount text-right', 'font-bold text-brand-700' => ($line['key'] === 'payable'), 'font-semibold' => $line['total'] ?? false])>{{ Money::format($line['amount_minor']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>

            @if (! empty($report->issues))
                <x-ui.card>
                    <h2 class="mb-3 font-semibold">{{ __('tax.issues_title') }}</h2>
                    <ul class="space-y-2">
                        @foreach ($report->issues as $issue)
                            <li class="flex items-start gap-2 text-sm">
                                <x-ui.icon name="warning" :class="'mt-0.5 size-4 shrink-0 '.($issue['severity'] === 'error' ? 'text-red-600' : 'text-amber-600')" />
                                <span>{{ trans_choice('tax.issues.'.$issue['key'], $issue['count'] ?? 1, ($issue['params'] ?? []) + ['count' => $issue['count'] ?? 0]) }}
                                    @if ($issue['severity'] === 'error')<x-ui.badge tone="danger" class="ml-1">{{ __('tax.blocks') }}</x-ui.badge>@endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.rules_used') }}</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach ($report->rules ?? [] as $rule)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-soft px-3 py-1.5 text-xs">
                            <span class="font-mono">{{ $rule['code'] }}</span>
                            @isset($rule['value'])<span class="font-semibold">{{ ($rule['kind'] ?? 'rate') === 'rate' ? rtrim(rtrim(number_format($rule['value'] / 100, 2, ',', ''), '0'), ',').'%' : Money::format($rule['value']) }}</span>@endisset
                            <span class="text-muted">v{{ $rule['version'] ?? 1 }}</span>
                            <x-ui.badge :tone="($rule['verified'] ?? false) ? 'success' : 'warning'">{{ ($rule['verified'] ?? false) ? __('tax.verified') : __('tax.unverified') }}</x-ui.badge>
                        </span>
                    @endforeach
                </div>
            </x-ui.card>

            @if (! empty($report->gate_results))
                <x-ui.card>
                    <h2 class="mb-3 font-semibold">{{ __('tax.gates_title') }}</h2>
                    <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($report->gate_results as $gate)
                            <li class="flex items-start gap-2 text-sm">
                                <x-ui.icon :name="$gate['passed'] ? 'check' : 'x'" :class="'mt-0.5 size-4 shrink-0 '.($gate['passed'] ? 'text-brand-600' : 'text-red-600')" />
                                <span>{{ __('tax.gates.'.$gate['key']) }}@if (! $gate['passed'] && ! empty($gate['params']['errors']))<span class="block text-xs text-red-600">{{ $gate['params']['errors'] }}</span>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            @if ($attempts->isNotEmpty())
                <x-ui.card padding="p-0">
                    <div class="px-5 pt-5 sm:px-6">
                        <h2 class="font-semibold">{{ __('tax.attempts.title') }}</h2>
                        <p class="text-xs text-muted">{{ __('tax.attempts.hint') }}</p>
                    </div>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[36rem]">
                            <thead><tr class="border-b border-line">
                                <th class="th">{{ __('tax.attempts.started') }}</th>
                                <th class="th">{{ __('tax.attempts.gateway') }}</th>
                                <th class="th">{{ __('ui.common.status') }}</th>
                                <th class="th">{{ __('tax.receipt') }}</th>
                                <th class="th">{{ __('tax.attempts.key') }}</th>
                            </tr></thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($attempts as $attempt)
                                    <tr class="align-top">
                                        <td class="td tabular-nums">{{ $attempt->started_at->format('d.m.Y H:i') }}<p class="text-xs text-muted">{{ $attempt->actor_name }}</p></td>
                                        <td class="td">{{ $attempt->gateway }}</td>
                                        <td class="td">
                                            <x-ui.badge :tone="$attempt->tone()">{{ __('tax.attempts.statuses.'.$attempt->status) }}</x-ui.badge>
                                            @if ($attempt->verified_by)<p class="mt-1 text-xs text-muted">{{ __('tax.attempts.verified_by', ['who' => $attempt->verified_by, 'when' => $attempt->verified_at?->format('d.m.Y H:i')]) }}</p>@endif
                                            @foreach ($attempt->errors ?? [] as $error)<p class="mt-1 max-w-xs text-xs text-red-600">{{ $error }}</p>@endforeach
                                            @if ($attempt->resolution_note)<p class="mt-1 max-w-xs text-xs text-muted">“{{ $attempt->resolution_note }}”</p>@endif
                                        </td>
                                        <td class="td font-mono text-xs">{{ $attempt->receipt_number ?? '—' }}</td>
                                        <td class="td font-mono text-xs">{{ substr($attempt->idempotency_key, 0, 12) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endif

            @if ($approvals->isNotEmpty())
                <x-ui.card>
                    <h2 class="mb-3 font-semibold">{{ __('tax.approvals.title') }}</h2>
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($approvals as $approval)
                            <li class="flex flex-wrap items-start justify-between gap-2 py-2.5">
                                <span>
                                    <span class="font-semibold">{{ $approval->source === 'mandate' ? __('tax.by_mandate', ['id' => $approval->mandate_id]) : $approval->user_name }}</span>
                                    <span class="text-muted">· {{ $approval->approved_at->format('d.m.Y H:i') }} · {{ Money::format($approval->payable_minor) }} · <span class="font-mono text-xs">{{ substr($approval->version_hash, 0, 12) }}</span></span>
                                    @if ($approval->declaration)<span class="block text-xs text-muted">{{ __('tax.approvals.with_declaration') }}</span>@endif
                                    @if ($approval->invalidated_at)<span class="block text-xs text-muted">{{ __('tax.approvals.ended', ['when' => $approval->invalidated_at->format('d.m.Y H:i'), 'reason' => $approval->invalidation_reason]) }}</span>@endif
                                </span>
                                <x-ui.badge :tone="$approval->invalidated_at ? 'neutral' : 'success'">{{ $approval->invalidated_at ? __('tax.approvals.inactive') : __('tax.approvals.active') }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-5">
            <x-ui.card>
                <dl class="space-y-3 text-sm">
                    <div><dt class="label">{{ __('tax.payable') }}</dt><dd class="amount text-2xl font-bold text-brand-700">{{ Money::format($report->payable_minor) }}</dd></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><dt class="label">{{ __('tax.base') }}</dt><dd class="amount">{{ Money::format($report->base_minor) }}</dd></div>
                        <div><dt class="label">{{ __('tax.tax') }}</dt><dd class="amount">{{ Money::format($report->tax_minor) }}</dd></div>
                    </div>
                    <div><dt class="label">{{ __('tax.version') }}</dt><dd class="font-mono text-xs">{{ substr($report->version_hash, 0, 16) }}</dd></div>
                    @if ($report->approved_at)
                        <div><dt class="label">{{ __('tax.approved_by') }}</dt>
                            <dd>{{ $report->approval_source === 'mandate' ? __('tax.by_mandate', ['id' => $report->mandate_id]) : $report->approved_by_name }} · {{ $report->approved_at->format('d.m.Y H:i') }}</dd></div>
                    @endif
                    @if ($report->scheduled_at && $report->status === S::Scheduled)
                        <div><dt class="label">{{ __('tax.cancel_window_ends') }}</dt><dd class="font-semibold text-amber-700">{{ $report->scheduled_at->format('d.m.Y H:i') }}</dd></div>
                    @endif
                    @if ($report->submitted_at)
                        <div><dt class="label">{{ __('tax.submitted_at') }}</dt><dd>{{ $report->submitted_at->format('d.m.Y H:i') }}</dd></div>
                    @endif
                    @if ($report->receipt_number)
                        <div><dt class="label">{{ __('tax.receipt') }}</dt><dd class="font-mono font-semibold">{{ $report->receipt_number }}</dd>
                            @if (($report->authority_response['sandbox'] ?? false))<dd class="text-xs text-amber-700">{{ __('tax.sandbox_receipt') }}</dd>@endif</div>
                    @endif
                    @if ($signature)
                        <div><dt class="label">{{ __('tax.signature') }}</dt><dd class="text-xs">{{ __('tax.signers.'.$signature->signer) }} · {{ $signature->actor_name }} · {{ $signature->created_at->format('d.m.Y H:i') }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>

            {{-- Actions: what is possible depends on the state and the person's permissions. --}}
            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.actions') }}</h2>
                <div class="space-y-2">
                    @if (in_array($report->status, [S::Prepared, S::Blocked, S::Rejected, S::PendingApproval], true))
                        <form method="post" action="{{ route('tax.reports.recalculate', $report) }}">@csrf
                            <x-ui.button type="submit" variant="secondary" class="w-full" icon="calculator">{{ __('tax.recalculate') }}</x-ui.button>
                        </form>
                    @endif

                    @if (in_array($report->status, [S::Prepared, S::Blocked, S::Rejected], true) && ! $canApprove)
                        <form method="post" action="{{ route('tax.reports.request', $report) }}">@csrf
                            <x-ui.button type="submit" variant="soft" class="w-full" :disabled="$errors_ !== []">{{ __('tax.request') }}</x-ui.button>
                        </form>
                    @endif

                    @if ($canApprove && in_array($report->status, [S::Prepared, S::PendingApproval, S::Blocked, S::Rejected], true))
                        @if ($canSubmit)
                            <x-tax.submit-dialog :report="$report" :gateway="$gatewayName" />
                        @endif
                        <form method="post" action="{{ route('tax.reports.approve', $report) }}">@csrf
                            <input type="hidden" name="version" value="{{ $report->version_hash }}">
                            <x-ui.confirm-button :message="__('tax.confirm_approve', ['hours' => $settings->cancel_window_hours])" :variant="$canSubmit ? 'secondary' : 'primary'" size="md" class="w-full" :disabled="$errors_ !== []">{{ $canSubmit ? __('tax.approve_with_window', ['hours' => $settings->cancel_window_hours]) : __('tax.approve') }}</x-ui.confirm-button>
                        </form>
                        @if ($report->unverifiedRules() !== [])
                            <p class="text-xs text-amber-700">{{ __('tax.approve_unverified_warning') }}</p>
                        @endif
                    @endif

                    @if ($report->status === S::Scheduled && $canSubmit && $report->approval_source === 'user')
                        <form method="post" action="{{ route('tax.reports.submit', $report) }}">@csrf
                            <x-ui.confirm-button :message="__('tax.confirm_submit_now')" variant="primary" size="md" class="w-full" icon="send">{{ __('tax.submit_now') }}</x-ui.confirm-button>
                        </form>
                    @endif

                    @if ($canApprove && in_array($report->status, [S::PendingApproval, S::Scheduled, S::AwaitingSignature, S::Blocked], true))
                        <form method="post" action="{{ route('tax.reports.cancel', $report) }}">@csrf
                            <x-ui.confirm-button :message="__('tax.confirm_cancel')" variant="secondary" size="md" class="w-full !text-red-600">{{ __('tax.cancel') }}</x-ui.confirm-button>
                        </form>
                    @endif

                    @if ($errors_ !== [] && ! $report->status->isFinal())
                        <p class="text-xs text-red-600">{{ __('tax.fix_issues_first') }}</p>
                    @endif

                    @if ($report->status->isFinal())
                        <p class="text-sm text-muted">{{ __('tax.final_note') }}</p>
                    @endif
                </div>
            </x-ui.card>

            {{-- The outcome is open: never resent; the gateway or a person who checked the cabinet settles it. --}}
            @if ($report->status->awaitsVerification())
                <x-ui.card>
                    <h2 class="mb-1 font-semibold">{{ __('tax.resolve.title') }}</h2>
                    <p class="mb-3 text-xs text-muted">{{ $report->status === S::Unknown ? __('tax.resolve.unknown_hint') : __('tax.resolve.submitted_hint') }}</p>
                    @if ($canSubmit)
                        <form method="post" action="{{ route('tax.reports.verify', $report) }}" class="mb-4">@csrf
                            <x-ui.button type="submit" variant="secondary" class="w-full" icon="search">{{ __('tax.resolve.verify') }}</x-ui.button>
                        </form>
                    @endif
                    @if ($canApprove && $canSubmit)
                        <form method="post" action="{{ route('tax.reports.resolve', $report) }}" class="space-y-3 border-t border-line pt-4">
                            @csrf
                            <p class="text-xs font-semibold">{{ __('tax.resolve.manual') }}</p>
                            <x-ui.select name="outcome" :label="__('tax.resolve.outcome')" required>
                                <option value="accepted">{{ __('tax.resolve.outcomes.accepted') }}</option>
                                <option value="rejected">{{ __('tax.resolve.outcomes.rejected') }}</option>
                                @if ($report->status === S::Unknown)
                                    <option value="not_received">{{ __('tax.resolve.outcomes.not_received') }}</option>
                                @endif
                            </x-ui.select>
                            <x-ui.input name="receipt" :label="__('tax.receipt')" :hint="__('tax.resolve.receipt_hint')" />
                            <div>
                                <x-ui.textarea name="note" :label="__('tax.resolve.note')" rows="3" />
                                <p class="mt-1 text-xs text-muted">{{ __('tax.resolve.note_hint') }}</p>
                            </div>
                            <x-ui.confirm-button :message="__('tax.resolve.confirm')" variant="primary" size="md" class="w-full">{{ __('tax.resolve.save') }}</x-ui.confirm-button>
                        </form>
                    @endif
                </x-ui.card>
            @endif

            @if ($report->status === S::AwaitingSignature)
                <x-ui.card>
                    <h2 class="mb-1 font-semibold">{{ __('tax.device.title') }}</h2>
                    <p class="mb-3 text-xs text-muted">{{ __('tax.device.hint') }}</p>
                    <p class="label">{{ __('tax.device.hash') }}</p>
                    <p class="mb-2 break-all rounded-xl bg-soft px-3 py-2 font-mono text-xs">{{ $documentHash }}</p>
                    <x-ui.copy :value="$documentHash" class="mb-4" />
                    <form method="post" action="{{ route('tax.reports.signature', $report) }}" class="space-y-3">
                        @csrf
                        <x-ui.textarea name="signature" :label="__('tax.device.signature')" rows="5" />
                        <x-ui.button type="submit" class="w-full" icon="signature">{{ __('tax.device.submit') }}</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
</x-layouts.app>
