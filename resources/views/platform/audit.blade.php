<x-layouts.platform :title="__('platform.nav.audit')">
    <x-ui.page-header :title="__('platform.audit.title')" :subtitle="__('platform.audit.subtitle')" />

    <x-ui.card padding="p-0">
        <form method="get" class="grid grid-cols-1 gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_9rem_9rem_auto]">
            <select name="action" class="field" aria-label="{{ __('platform.audit.action') }}">
                <option value="">{{ __('platform.audit.all_actions') }}</option>
                @foreach ($actions as $action)<option value="{{ $action }}" @selected(request('action') === $action)>{{ __('platform.audit.actions.'.$action) }}</option>@endforeach
            </select>
            <select name="staff_id" class="field" aria-label="{{ __('platform.audit.staff') }}">
                <option value="">{{ __('platform.audit.all_staff') }}</option>
                @foreach ($staff as $member)<option value="{{ $member->id }}" @selected((int) request('staff_id') === $member->id)>{{ $member->name }}</option>@endforeach
            </select>
            <input type="text" name="tenant_id" value="{{ request('tenant_id') }}" placeholder="{{ __('platform.audit.company_slug') }}" class="field" aria-label="{{ __('platform.audit.company') }}">
            <input type="date" name="from" value="{{ request('from') }}" class="field" aria-label="{{ __('platform.common.from') }}">
            <input type="date" name="to" value="{{ request('to') }}" class="field" aria-label="{{ __('platform.common.to') }}">
            <div class="flex gap-2">
                <x-ui.button type="submit" variant="dark">{{ __('platform.common.filter') }}</x-ui.button>
                @if (request()->hasAny(['action', 'staff_id', 'tenant_id', 'from', 'to']))<x-ui.button :href="platform_route('audit')" variant="ghost">{{ __('platform.common.reset') }}</x-ui.button>@endif
            </div>
        </form>

        @if ($logs->isEmpty())
            <x-ui.empty-state :title="__('platform.audit.empty')" icon="clock" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('platform.audit.when') }}</th>
                        <th class="th">{{ __('platform.audit.staff') }}</th>
                        <th class="th">{{ __('platform.audit.action') }}</th>
                        <th class="th">{{ __('platform.audit.company') }}</th>
                        <th class="th">{{ __('platform.audit.reason') }}</th>
                        <th class="th">IP</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($logs as $log)
                            <tr>
                                <td class="td whitespace-nowrap tabular-nums">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
                                <td class="td">{{ $log->staff_name ?? '—' }}</td>
                                <td class="td font-semibold">{{ __('platform.audit.actions.'.$log->action) }}@if (! empty($log->details['page']))<span class="font-normal text-muted"> · {{ $log->details['page'] }}</span>@endif</td>
                                <td class="td">@if ($log->tenant_id)<a href="{{ platform_route('companies.show', ['tenant' => $log->tenant_id]) }}" class="text-brand-700 hover:underline">{{ $log->tenant_id }}</a>@else — @endif</td>
                                <td class="td max-w-[18rem] text-muted">{{ $log->reason ?? '—' }}</td>
                                <td class="td font-mono text-xs text-muted">{{ $log->ip }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line p-4">{{ $logs->links() }}</div>
        @endif
    </x-ui.card>
</x-layouts.platform>
