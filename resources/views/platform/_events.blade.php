@if ($events->isEmpty())
    <x-ui.empty-state :title="__('platform.audit.empty')" icon="clock" class="py-8" />
@else
    <ul class="divide-y divide-line">
        @foreach ($events as $event)
            <li class="flex items-start justify-between gap-3 px-5 py-3 text-sm">
                <div class="min-w-0">
                    <p class="font-semibold">{{ __('platform.audit.actions.'.$event->action) }}</p>
                    <p class="truncate text-xs text-muted">
                        {{ $event->staff_name ?? __('platform.audit.system') }}@if ($event->tenant_id) · {{ $event->tenant_id }}@endif
                    </p>
                </div>
                <span class="shrink-0 text-xs tabular-nums text-muted">{{ $event->created_at->format('d.m H:i') }}</span>
            </li>
        @endforeach
    </ul>
@endif
