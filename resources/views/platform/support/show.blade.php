<x-layouts.platform :title="__('platform.support.title')">
    {{-- Support mode: a loud, countdown-bearing bar. Everything below is read-only. --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-3xl bg-amber-400 px-5 py-3 text-ink"
         x-data="{ left: {{ (int) max(0, now()->diffInSeconds($session->expires_at, false)) }}, tick() { if (this.left > 0) this.left--; if (this.left === 0) window.location = @js(platform_route('companies.show', $company)) } }"
         x-init="setInterval(() => tick(), 1000)">
        <div class="flex items-center gap-3">
            <x-ui.icon name="eye" class="size-5" />
            <div>
                <p class="text-sm font-bold">{{ __('platform.support.banner', ['company' => $company->name]) }}</p>
                <p class="text-xs">{{ __('platform.support.readonly') }} · <span class="tabular-nums" x-text="String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0')"></span></p>
            </div>
        </div>
        <form method="post" action="{{ platform_route('support.end', ['session' => $session->id]) }}">@csrf @method('DELETE')
            <x-ui.button type="submit" variant="dark" size="sm">{{ __('platform.support.end') }}</x-ui.button>
        </form>
    </div>

    <x-ui.page-header :title="$company->name" :subtitle="__('platform.support.reason').': '.$session->reason" />

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach (['documents', 'contractors', 'transactions'] as $key)
            <a href="{{ platform_route('support.show', ['tenant' => $company, 'tab' => $key]) }}" @class(['card block p-5 transition hover:-translate-y-0.5', 'ring-2 ring-ink' => $tab === $key])>
                <p class="text-xs font-semibold text-muted">{{ __('platform.support.tabs.'.$key) }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $counts[$key] }}</p>
            </a>
        @endforeach
    </div>

    <x-ui.card padding="p-0">
        @if (count($rows) === 0)
            <x-ui.empty-state :title="__('platform.support.empty')" icon="document" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem]">
                    @if ($tab === 'documents')
                        <thead><tr class="border-b border-line"><th class="th">{{ __('ui.documents.fields.number') }}</th><th class="th">{{ __('ui.documents.fields.contractor') }}</th><th class="th">{{ __('ui.documents.fields.issue_date') }}</th><th class="th">{{ __('ui.common.status') }}</th><th class="th text-right">{{ __('ui.documents.fields.total') }}</th></tr></thead>
                        <tbody class="divide-y divide-line">@foreach ($rows as $row)
                            <tr><td class="td font-semibold">{{ $row['number'] }}</td><td class="td">{{ $row['contractor'] }}</td><td class="td tabular-nums">{{ $row['date'] }}</td><td class="td"><x-ui.badge :tone="$row['tone']">{{ $row['status'] }}</x-ui.badge></td><td class="td amount text-right font-semibold">{{ $row['total'] }}</td></tr>
                        @endforeach</tbody>
                    @elseif ($tab === 'contractors')
                        <thead><tr class="border-b border-line"><th class="th">{{ __('ui.contractors.fields.name') }}</th><th class="th">{{ __('ui.contractors.fields.stir') }}</th><th class="th">{{ __('ui.contractors.fields.type') }}</th><th class="th">{{ __('ui.common.status') }}</th></tr></thead>
                        <tbody class="divide-y divide-line">@foreach ($rows as $row)
                            <tr><td class="td font-semibold">{{ $row['name'] }}</td><td class="td">{{ $row['stir'] }}</td><td class="td">{{ $row['type'] }}</td><td class="td">{{ $row['status'] }}</td></tr>
                        @endforeach</tbody>
                    @else
                        <thead><tr class="border-b border-line"><th class="th">{{ __('ui.bank.fields.date') }}</th><th class="th">{{ __('ui.bank.fields.account') }}</th><th class="th">{{ __('ui.bank.fields.category') }}</th><th class="th">{{ __('ui.bank.fields.type') }}</th><th class="th text-right">{{ __('ui.bank.fields.amount') }}</th></tr></thead>
                        <tbody class="divide-y divide-line">@foreach ($rows as $row)
                            <tr><td class="td tabular-nums">{{ $row['date'] }}</td><td class="td">{{ $row['account'] }}</td><td class="td text-muted">{{ $row['category'] }}</td><td class="td">{{ $row['type'] }}</td><td class="td amount text-right font-semibold">{{ $row['amount'] }}</td></tr>
                        @endforeach</tbody>
                    @endif
                </table>
            </div>
        @endif
    </x-ui.card>
    <p class="mt-4 text-xs text-muted">{{ __('platform.support.footer') }}</p>
</x-layouts.platform>
