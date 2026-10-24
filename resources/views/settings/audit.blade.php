<x-layouts.app :title="__('ui.settings.audit')">
    @include('settings._nav')

    <form method="get" class="mb-4 flex flex-wrap items-end gap-2">
        <x-ui.select name="actor" :value="request('actor')" :placeholder="__('ui.audit.all_actors')" class="w-48" onchange="this.form.submit()">
            @foreach (['user', 'ai', 'autopilot', 'system', 'platform'] as $actor)
                <option value="{{ $actor }}" @selected(request('actor') === $actor)>{{ __('ui.actors.'.$actor) }}</option>
            @endforeach
        </x-ui.select>
    </form>

    <x-ui.card padding="p-0">
        @if ($logs->isEmpty())
            <x-ui.empty-state :title="__('ui.audit.empty')" :text="__('ui.audit.empty_text')" icon="clock" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('ui.audit.when') }}</th>
                        <th class="th">{{ __('ui.audit.who') }}</th>
                        <th class="th">{{ __('ui.audit.what') }}</th>
                        <th class="th">{{ __('ui.audit.reason') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($logs as $log)
                            @php
                                $tone = ['platform' => 'warning', 'ai' => 'info', 'autopilot' => 'warning', 'system' => 'neutral'][$log->actor_type] ?? null;
                                $actionKey = 'ui.audit.actions.'.$log->action;
                                $details = collect($log->details ?? [])->except('page')->filter(fn ($v) => is_scalar($v) && $v !== '')->map(fn ($v, $k) => $k.': '.(str_ends_with($k, '_minor') ? \App\Support\Money::format((int) $v) : $v));
                            @endphp
                            <tr>
                                <td class="td whitespace-nowrap tabular-nums">{{ $log->created_at->format('d.m.Y H:i') }}</td>
                                <td class="td">
                                    <span class="font-semibold">{{ $log->actor_name }}</span>
                                    @if ($tone)<x-ui.badge :tone="$tone" class="ml-1">{{ __('ui.actors.'.$log->actor_type) }}</x-ui.badge>@endif
                                </td>
                                <td class="td">
                                    {{ \Illuminate\Support\Facades\Lang::has($actionKey) ? __($actionKey) : $log->action }}
                                    @if (! empty($log->details['page'])) <span class="text-muted">· {{ __('ui.audit.pages.'.$log->details['page']) }}</span>@endif
                                    @if ($details->isNotEmpty())<p class="mt-0.5 text-xs text-muted">{{ $details->implode(' · ') }}</p>@endif
                                </td>
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
