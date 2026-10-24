<x-layouts.app :title="__('tax.agent.title')">
    <x-ui.page-header :title="__('tax.agent.title')" :subtitle="__('tax.agent.subtitle')">
        <x-slot:actions>
            <x-ui.button :href="route('tax.runs.create')" variant="secondary" icon="sparkles">{{ __('tax.workflow.title') }}</x-ui.button>
            @can('reports.approve')
                <form method="post" action="{{ route('tax.agent.check') }}">@csrf
                    <x-ui.button type="submit" icon="robot">{{ __('tax.agent.check_now') }}</x-ui.button>
                </form>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- A rehearsal: the cabinet's own calculation for a month is read and shown. Nothing is created, approved or sent. --}}
    @can('reports.approve')
        <x-ui.card class="mb-5">
            <h2 class="font-semibold">{{ __('tax.agent.rehearsal_title') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('tax.agent.rehearsal_hint') }}</p>
            <form method="post" action="{{ route('tax.agent.rehearse') }}" class="mt-3 flex flex-wrap items-end gap-3">@csrf
                <div>
                    <label class="label" for="rehearsal-month">{{ __('tax.agent.rehearsal_month') }}</label>
                    <input id="rehearsal-month" type="month" name="month" value="{{ now()->subMonth()->format('Y-m') }}" max="{{ now()->format('Y-m') }}" class="input" required>
                </div>
                <x-ui.button type="submit" variant="secondary" icon="eye">{{ __('tax.agent.rehearsal_button') }}</x-ui.button>
            </form>
        </x-ui.card>
    @endcan

    @if ($gatewayName !== 'desktop')
        <div class="mb-5 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">{{ __('tax.agent.not_selected', ['driver' => $gatewayName]) }}</div>
    @endif

    {{-- Shown once, right after pairing. Only its hash is stored. --}}
    @if ($token)
        <x-ui.card class="mb-5 ring-2 ring-brand-200">
            <h2 class="mb-1 font-semibold">{{ __('tax.agent.token_title') }}</h2>
            <p class="mb-3 text-sm text-muted">{{ __('tax.agent.token_hint') }}</p>
            <p class="label">{{ __('tax.agent.server_url') }}</p>
            <p class="mb-3 break-all rounded-xl bg-soft px-3 py-2 font-mono text-xs">{{ $host }}</p>
            <p class="label">{{ __('tax.agent.token') }}</p>
            <p class="mb-2 break-all rounded-xl bg-soft px-3 py-2 font-mono text-xs">{{ $token }}</p>
            <x-ui.copy :value="$token" />
        </x-ui.card>
    @endif

    @if ($active)
        <div x-data x-init="setTimeout(() => location.reload(), 4000)"></div>
    @endif

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-ui.card padding="p-0">
                <div class="px-5 pt-5 sm:px-6"><h2 class="font-semibold">{{ __('tax.agent.tasks') }}</h2></div>
                @if ($tasks->isEmpty())
                    <x-ui.empty-state :title="__('tax.agent.no_tasks')" icon="robot" />
                @else
                    <div class="mt-3 divide-y divide-line">
                        @foreach ($tasks as $task)
                            <div class="px-5 py-3 sm:px-6">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-semibold">{{ __('tax.agent.types.'.$task->type) }}@if ($task->report) · <a class="hover:underline" href="{{ route('tax.reports.show', $task->report) }}">{{ $task->report->title() }}</a>@endif</p>
                                        <p class="text-xs text-muted">{{ $task->created_at->format('d.m.Y H:i') }} · {{ $task->actor_name }}@if ($task->agent) · {{ $task->agent->name }}@endif</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <x-ui.badge :tone="$task->tone()">{{ __('tax.agent.statuses.'.$task->status) }}</x-ui.badge>
                                        @if ($task->isOpen() && $task->submission_attempt_id === null)
                                            @can('reports.approve')
                                                <form method="post" action="{{ route('tax.agent.cancel', $task) }}">@csrf
                                                    <x-ui.confirm-button :message="__('tax.agent.confirm_cancel')" size="sm">{{ __('tax.cancel') }}</x-ui.confirm-button>
                                                </form>
                                            @endcan
                                        @endif
                                    </div>
                                </div>

                                {{-- The form is filled in the real cabinet and nothing is sent yet: this answer is the final permission. --}}
                                @if ($task->status === 'needs_user' && $task->waiting_for === 'final_confirmation' && ($preview = $task->result['preview'] ?? null))
                                    @php $mismatches = $task->result['mismatches'] ?? []; @endphp
                                    <div class="mt-3 rounded-2xl bg-white p-4 ring-2 ring-brand-600">
                                        <p class="font-semibold">{{ __('tax.agent.final_title') }}</p>
                                        <p class="mt-1 text-sm text-muted">{{ __('tax.agent.final_hint') }}</p>
                                        <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                            @foreach (['type', 'period_start', 'period_end', 'base_minor', 'tax_minor', 'payable_minor'] as $field)
                                                <div class="flex justify-between gap-3 border-b border-line py-1 {{ in_array($field, $mismatches, true) ? 'text-red-600' : '' }}">
                                                    <dt class="text-muted">{{ __('tax.document_fields.'.$field) }}</dt>
                                                    <dd class="font-semibold">{{ str_ends_with($field, '_minor') ? \App\Support\Money::format((int) ($preview[$field] ?? 0)) : ($preview[$field] ?? '—') }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                        @if ($mismatches !== [])
                                            <p class="mt-3 text-sm font-semibold text-red-600">{{ __('tax.agent.final_mismatch') }}</p>
                                        @endif
                                        @can('reports.approve')
                                            <div class="mt-4 flex flex-wrap gap-2">
                                                <form method="post" action="{{ route('tax.agent.send', $task) }}">@csrf
                                                    <x-ui.button type="submit" icon="send" :disabled="$mismatches !== []">{{ __('tax.agent.final_yes') }}</x-ui.button>
                                                </form>
                                                <form method="post" action="{{ route('tax.agent.hold', $task) }}">@csrf
                                                    <x-ui.button type="submit" variant="secondary">{{ __('tax.agent.final_no') }}</x-ui.button>
                                                </form>
                                            </div>
                                        @endcan
                                    </div>
                                @elseif ($task->status === 'needs_user')
                                    <p class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 ring-1 ring-amber-200">{{ __('tax.agent.waiting_for_you', ['what' => __('tax.agent.waiting_for.'.$task->waiting_for), 'computer' => $task->agent?->name]) }}</p>
                                @endif
                                @if ($task->error)<p class="mt-2 text-xs text-red-600">{{ $task->error }}</p>@endif

                                {{-- A rehearsal's result: the cabinet's figures, read only. --}}
                                @if ($task->type === 'rehearsal' && $task->status === 'completed' && ($r = $task->result['rehearsal'] ?? null))
                                    <div class="mt-3 rounded-2xl bg-brand-50 p-4 ring-1 ring-brand-100">
                                        <p class="text-sm font-semibold text-brand-800">{{ __('tax.agent.rehearsal_result', ['period' => \Carbon\CarbonImmutable::parse($r['period_start'])->format('m.Y')]) }}</p>
                                        <dl class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                            @foreach ($r['lines'] as $line)
                                                <div class="flex justify-between gap-3 border-b border-brand-100 py-1 {{ ($line['total'] ?? false) ? 'font-semibold' : '' }}">
                                                    <dt>{{ __('tax.lines.'.$line['key'], $line['params'] ?? []) }}</dt>
                                                    <dd>{{ \App\Support\Money::format($line['amount_minor']) }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                        @foreach ($r['issues'] as $issue)
                                            <p class="mt-2 text-xs {{ ($issue['severity'] ?? '') === 'error' ? 'text-red-600' : 'text-amber-700' }}">{{ __('tax.issues.'.$issue['key'], ($issue['params'] ?? []) + ['count' => $issue['count'] ?? 0]) }}</p>
                                        @endforeach
                                        @if ($r['filed_receipt'])
                                            <p class="mt-2 text-xs text-brand-800">{{ __('tax.agent.rehearsal_filed', ['receipt' => $r['filed_receipt']]) }}</p>
                                        @endif
                                        <p class="mt-2 text-xs text-muted">{{ __('tax.agent.rehearsal_nothing_sent') }}</p>
                                    </div>
                                @endif

                                {{-- What the agent read in the cabinet, in one line, with the way to the full result. --}}
                                @if ($task->status === 'completed' && ($check = $checks[$task->result['portal_check'] ?? 0] ?? null))
                                    @php
                                        $seenReports = collect($check->snapshot['reports'] ?? []);
                                        $todo = collect($check->findings ?? [])->whereIn('severity', ['error', 'warning'])->count();
                                    @endphp
                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-brand-50 px-3 py-2 ring-1 ring-brand-100">
                                        <p class="text-sm text-brand-800">
                                            {{ __('tax.agent.seen_summary', ['reports' => $seenReports->count(), 'accepted' => $seenReports->where('status', 'accepted')->count(), 'todo' => $todo]) }}
                                        </p>
                                        <x-ui.button size="sm" :href="route('tax.portal')" icon="eye">{{ __('tax.agent.view_result') }}</x-ui.button>
                                    </div>
                                @endif

                                {{-- The cabinet that opened is not known to this company: the owner may confirm it is theirs. --}}
                                @if ($task->status === 'failed' && ($seen = $task->result['seen_identifier'] ?? null) && preg_match('/^\d{14}$/', $seen) && blank($settings->pinfl))
                                    @can('autopilot.manage')
                                        <form method="post" action="{{ route('tax.agent.link', $task) }}" class="mt-2 flex flex-wrap items-center gap-3 rounded-2xl bg-brand-50 px-3 py-2 ring-1 ring-brand-100">
                                            @csrf
                                            <p class="text-sm text-brand-800">{{ __('tax.agent.link_question', ['id' => $seen, 'company' => $settings->name]) }}</p>
                                            <x-ui.confirm-button :message="__('tax.agent.link_confirm', ['id' => $seen, 'company' => $settings->name])" variant="primary" size="sm" icon="check">{{ __('tax.agent.link_button') }}</x-ui.confirm-button>
                                        </form>
                                    @endcan
                                @endif

                                @if ($task->isOpen() || $loop->first)
                                    <ol class="mt-2 space-y-0.5 text-xs text-muted">
                                        @foreach (array_slice($task->events ?? [], -6) as $event)
                                            <li><span class="tabular-nums">{{ \Carbon\Carbon::parse($event['at'])->format('H:i:s') }}</span> · {{ $event['message'] }}</li>
                                        @endforeach
                                    </ol>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-5">
            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.agent.computers') }}</h2>
                @forelse ($agents as $agent)
                    <div class="flex items-start justify-between gap-3 border-b border-line py-2.5 last:border-0">
                        <div class="min-w-0 text-sm">
                            <p class="font-semibold">{{ $agent->name }}</p>
                            <p class="text-xs text-muted">{{ $agent->token_prefix }}… · {{ $agent->last_seen_at ? __('tax.agent.last_seen', ['time' => $agent->last_seen_at->diffForHumans()]) : __('tax.agent.never_seen') }}@if ($agent->agent_version) · v{{ $agent->agent_version }}@endif</p>
                            @if ($agent->environment && $agent->revoked_at === null)
                                <p class="text-xs {{ ($agent->environment['eimzo_reachable'] ?? false) ? 'text-brand-700' : 'text-red-600' }}">{{ ($agent->environment['eimzo_reachable'] ?? false) ? __('tax.agent.eimzo_ok', ['version' => $agent->environment['eimzo_version'] ?? '?']) : __('tax.agent.eimzo_missing') }}</p>
                            @endif
                        </div>
                        <div class="flex flex-col items-end gap-1.5">
                            <x-ui.badge :tone="$agent->revoked_at ? 'neutral' : ($agent->isOnline() ? 'success' : 'warning')">{{ $agent->revoked_at ? __('tax.agent.revoked_badge') : ($agent->isOnline() ? __('tax.agent.online') : __('tax.agent.offline')) }}</x-ui.badge>
                            @if ($agent->revoked_at === null)
                                @can('autopilot.manage')
                                    <form method="post" action="{{ route('tax.agent.revoke', $agent) }}">@csrf
                                        <x-ui.confirm-button :message="__('tax.agent.confirm_revoke')" size="sm">{{ __('tax.agent.revoke') }}</x-ui.confirm-button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('tax.agent.no_computers') }}</p>
                @endforelse
            </x-ui.card>

            @can('autopilot.manage')
                <x-ui.card>
                    <h2 class="mb-1 font-semibold">{{ __('tax.agent.pair_title') }}</h2>
                    <p class="mb-3 text-xs text-muted">{{ __('tax.agent.pair_hint') }}</p>
                    <form method="post" action="{{ route('tax.agent.pair') }}" class="space-y-3">
                        @csrf
                        <x-ui.input name="name" :label="__('tax.agent.computer_name')" placeholder="Buxgalter-PC" :required="true" />
                        <x-ui.button type="submit" class="w-full" icon="link">{{ __('tax.agent.pair') }}</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan

            <x-ui.card>
                <h2 class="mb-2 font-semibold">{{ __('tax.agent.security_title') }}</h2>
                <ul class="space-y-1.5 text-xs text-muted">
                    @foreach (['no_key', 'password', 'approval', 'form_check', 'unknown'] as $point)
                        <li class="flex gap-2"><x-ui.icon name="shield" class="size-4 shrink-0 text-brand-600" />{{ __('tax.agent.security.'.$point) }}</li>
                    @endforeach
                </ul>
            </x-ui.card>
        </div>
    </div>
</x-layouts.app>
