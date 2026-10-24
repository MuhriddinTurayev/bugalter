<x-layouts.app :title="__('tax.workflow.title')">
    <x-ui.page-header :title="__('tax.workflow.title')" :subtitle="__('tax.workflow.subtitle')">
        <x-slot:actions>
            <x-ui.button :href="route('tax.index')" variant="secondary">{{ __('tax.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('tax.runs._stepper', ['current' => 1])

    @if ($active)
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800 ring-1 ring-brand-100">
            <span>{{ __('tax.workflow.active_run', ['time' => $active->created_at->format('H:i')]) }}</span>
            <x-ui.button size="sm" :href="route('tax.runs.show', $active)">{{ __('tax.open') }}</x-ui.button>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card>
                <h2 class="mb-4 font-semibold">{{ __('tax.workflow.company') }}</h2>
                <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="label">{{ __('tax.workflow.company_name') }}</dt><dd class="font-semibold">{{ $settings->name }}</dd></div>
                    <div><dt class="label">{{ __('tax.workflow.tin') }}</dt>
                        <dd class="font-mono">{{ $settings->stir ?: '—' }}</dd>
                        @unless (preg_match('/^\d{9}$/', (string) $settings->stir))<dd class="text-xs text-red-600">{{ __('tax.workflow.problems.no_tin') }}</dd>@endunless
                    </div>
                    <div><dt class="label">{{ __('tax.profile.regime') }}</dt><dd>{{ $settings->regime()->label() }}</dd></div>
                    <div><dt class="label">{{ __('tax.vat_payer') }}</dt><dd>{{ $settings->vat_payer ? __('tax.workflow.yes') : __('tax.workflow.no') }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-1 font-semibold">{{ __('tax.workflow.sources') }}</h2>
                <p class="mb-4 text-xs text-muted">{{ __('tax.workflow.sources_hint') }}</p>
                <ul class="divide-y divide-line text-sm">
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <span>{{ __('tax.workflow.source.books') }}</span>
                        <x-ui.badge tone="success">{{ __('tax.workflow.source.accounts', ['count' => $sources['accounts']]) }}</x-ui.badge>
                    </li>
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <span>{{ __('tax.workflow.source.portal') }}
                            @if (! $sources['portal_access'])<span class="block text-xs text-muted">{{ __('tax.workflow.portal_skipped.no_access') }}</span>
                            @elseif ($sources['portal_errors'] !== [])<span class="block text-xs text-muted">{{ implode(' ', $sources['portal_errors']) }}</span>
                            @elseif (! $sources['key'])<span class="block text-xs text-muted">{{ __('tax.workflow.portal_skipped.no_key') }}</span>
                            @elseif ($sources['portal'] === 'sandbox')<span class="block text-xs text-amber-700">{{ __('tax.portal.sandbox_note') }}</span>@endif
                        </span>
                        @if ($sources['portal_access'] && $sources['portal_errors'] === [] && $sources['key'])
                            <x-ui.badge :tone="$sources['portal'] === 'sandbox' ? 'warning' : 'success'">{{ __('tax.workflow.source.connected') }}</x-ui.badge>
                        @else
                            <x-ui.badge>{{ __('tax.workflow.source.not_connected') }}</x-ui.badge>
                        @endif
                    </li>
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <span>{{ __('tax.workflow.source.signing') }}
                            <span class="block text-xs text-muted">{{ $sources['signer_mode'] === 'device' ? __('tax.signers.device') : ($sources['key'] ? __('tax.workflow.source.key_valid', ['date' => $sources['key']->valid_to->format('d.m.Y')]) : __('tax.portal.errors.no_key')) }}</span>
                        </span>
                        <x-ui.badge :tone="$sources['signer_mode'] === 'device' || $sources['key'] ? 'success' : 'warning'">{{ $sources['signer_mode'] === 'device' || $sources['key'] ? __('tax.workflow.source.ready') : __('tax.workflow.source.missing') }}</x-ui.badge>
                    </li>
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <span>{{ __('tax.workflow.source.gateway') }}
                            <span class="block text-xs text-muted">{{ __('tax.submit_dialog.targets.'.(in_array($sources['gateway'], ['portal', 'http', 'sandbox'], true) ? $sources['gateway'] : 'http')) }}</span>
                        </span>
                        <x-ui.badge :tone="$sources['gateway'] === 'sandbox' ? 'warning' : 'success'">{{ $sources['gateway'] === 'sandbox' ? __('tax.workflow.source.sandbox') : __('tax.workflow.source.live') }}</x-ui.badge>
                    </li>
                </ul>
            </x-ui.card>
        </div>

        <div class="space-y-5">
            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.workflow.choose_period') }}</h2>
                <form method="post" action="{{ route('tax.runs.store') }}" class="space-y-3" x-data="{ sending: false }" x-on:submit="sending = true">
                    @csrf
                    <x-ui.input name="month" type="month" :label="__('tax.workflow.month')" :value="$month" :max="now()->format('Y-m')" :hint="__('tax.workflow.month_hint')" :required="true" />
                    @if ($active)
                        <x-ui.button type="button" class="w-full" icon="sparkles" :disabled="true">{{ __('tax.workflow.start') }}</x-ui.button>
                    @else
                        <x-ui.button type="submit" class="w-full" icon="sparkles" ::disabled="sending">{{ __('tax.workflow.start') }}</x-ui.button>
                    @endif
                </form>
                <p class="mt-3 text-xs text-muted">{{ __('tax.workflow.nothing_submitted') }}</p>
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.workflow.recent') }}</h2>
                @forelse ($recent as $item)
                    <a href="{{ route('tax.runs.show', $item) }}" class="-mx-2 flex items-center justify-between gap-3 rounded-2xl px-2 py-2 transition hover:bg-soft">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold">{{ __('ui.months_full.'.$item->month()->month) }} {{ $item->month()->year }}</span>
                            <span class="text-xs text-muted">{{ $item->created_at->format('d.m.Y H:i') }} · {{ $item->actor_name }}</span>
                        </span>
                        <x-ui.badge :tone="['completed' => 'success', 'failed' => 'danger'][$item->status] ?? 'info'">{{ __('tax.workflow.run_statuses.'.$item->status) }}</x-ui.badge>
                    </a>
                @empty
                    <p class="text-sm text-muted">{{ __('tax.workflow.no_runs') }}</p>
                @endforelse
            </x-ui.card>
        </div>
    </div>
</x-layouts.app>
