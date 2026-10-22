<x-layouts.app :title="__('ui.settings.audit')">
    @include('settings._nav')

    <x-ui.card padding="p-0">
        @if ($logs->isEmpty())
            <x-ui.empty-state :title="__('ui.audit.empty')" :text="__('ui.audit.empty_text')" icon="clock" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('ui.audit.when') }}</th>
                        <th class="th">{{ __('ui.audit.who') }}</th>
                        <th class="th">{{ __('ui.audit.what') }}</th>
                        <th class="th">{{ __('ui.audit.reason') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($logs as $log)
                            <tr>
                                <td class="td tabular-nums whitespace-nowrap">{{ $log->created_at->format('d.m.Y H:i') }}</td>
                                <td class="td">
                                    <span class="font-semibold">{{ $log->actor_name }}</span>
                                    @if ($log->actor_type === 'platform')<x-ui.badge tone="warning" class="ml-1">{{ __('ui.audit.platform') }}</x-ui.badge>@endif
                                </td>
                                <td class="td">{{ __('ui.audit.actions.'.$log->action) }}@if (! empty($log->details['page'])) <span class="text-muted">· {{ __('ui.audit.pages.'.$log->details['page']) }}</span>@endif</td>
                                <td class="td text-muted">{{ $log->reason ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line p-4">{{ $logs->links() }}</div>
        @endif
    </x-ui.card>
    <p class="mt-4 text-xs text-muted">{{ __('ui.audit.note') }}</p>
</x-layouts.app>
