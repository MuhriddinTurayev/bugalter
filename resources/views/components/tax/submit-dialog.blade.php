@props(['report', 'gateway', 'size' => 'md'])

{{--
    "Submit?" for one report: what will be sent, where, and one button that approves this version and submits
    it now. Shown only to someone with reports.approve and reports.submit; the server checks both again.
--}}
@php
    use App\Support\Money;

    $unverified = $report->unverifiedRules() !== [];
    $target = __('tax.submit_dialog.targets.'.(in_array($gateway, ['portal', 'http', 'sandbox'], true) ? $gateway : 'http'));
@endphp

<div x-data="{ open: false, sending: false }" {{ $attributes }}>
    <x-ui.button type="button" :size="$size" icon="send" class="w-full" x-on:click="open = true" :disabled="$report->blockingIssues() !== []">
        {{ __('tax.submit_dialog.button') }}
    </x-ui.button>

    <div x-cloak x-show="open" x-transition.opacity class="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-4 sm:items-center" x-on:keydown.escape.window="if (! sending) open = false">
        <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl" x-on:click.outside="if (! sending) open = false" role="dialog" aria-modal="true" aria-labelledby="submit-dialog-{{ $report->id }}">
            <div class="mb-4 flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-brand-50 text-brand-700"><x-ui.icon name="send" class="size-5" /></span>
                <div>
                    <h2 id="submit-dialog-{{ $report->id }}" class="text-lg font-semibold">{{ __('tax.submit_dialog.title') }}</h2>
                    <p class="text-sm text-muted">{{ $target }}</p>
                </div>
            </div>

            <dl class="space-y-2 rounded-2xl bg-soft p-4 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('tax.report') }}</dt><dd class="text-right font-semibold">{{ $report->title() }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('tax.due_date') }}</dt><dd class="tabular-nums">{{ $report->due_date?->format('d.m.Y') ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('tax.tax') }}</dt><dd class="amount">{{ Money::format($report->tax_minor) }}</dd></div>
                <div class="flex justify-between gap-3 border-t border-line pt-2"><dt class="font-semibold">{{ __('tax.payable') }}</dt><dd class="amount text-lg font-bold text-brand-700">{{ Money::format($report->payable_minor) }}</dd></div>
            </dl>

            <ul class="mt-4 space-y-1.5 text-xs text-muted">
                <li class="flex gap-2"><x-ui.icon name="shield" class="size-4 shrink-0 text-brand-600" />{{ __('tax.submit_dialog.gates') }}</li>
                <li class="flex gap-2"><x-ui.icon name="signature" class="size-4 shrink-0 text-brand-600" />{{ __('tax.submit_dialog.signature') }}</li>
            </ul>

            @if ($unverified)
                <p class="mt-3 rounded-2xl bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200">{{ __('tax.approve_unverified_warning') }}</p>
            @endif

            <form method="post" action="{{ route('tax.reports.approve-submit', $report) }}" class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end" x-on:submit="sending = true">
                @csrf
                <input type="hidden" name="version" value="{{ $report->version_hash }}">
                <x-ui.button type="button" variant="secondary" x-on:click="open = false" ::disabled="sending">{{ __('tax.submit_dialog.no') }}</x-ui.button>
                <x-ui.button type="submit" icon="check" ::disabled="sending">
                    <span x-show="! sending">{{ __('tax.submit_dialog.yes') }}</span>
                    <span x-show="sending" x-cloak>{{ __('tax.submit_dialog.sending') }}</span>
                </x-ui.button>
            </form>
        </div>
    </div>
</div>
