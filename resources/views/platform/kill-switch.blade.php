@php $on = $state['active']; @endphp

<x-layouts.platform :title="__('platform.nav.kill_switch')">
    <x-ui.page-header :title="__('platform.kill_switch.title')" :subtitle="__('platform.kill_switch.subtitle')" />

    <div class="grid max-w-4xl gap-5 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2 {{ $on ? 'ring-2 ring-amber-400' : '' }}">
            <div class="flex items-start gap-4">
                <span @class(['grid size-12 shrink-0 place-items-center rounded-full', 'bg-amber-100 text-amber-700' => $on, 'bg-brand-50 text-brand-600' => ! $on])><x-ui.icon name="power" class="size-6" /></span>
                <div>
                    <p class="text-lg font-semibold">{{ $on ? __('platform.kill_switch.state_on') : __('platform.kill_switch.state_off') }}</p>
                    @if ($on)
                        <p class="mt-1 text-sm text-muted">{{ __('platform.kill_switch.since', ['by' => $state['by'], 'at' => \Illuminate\Support\Carbon::parse($state['at'])->format('d.m.Y H:i')]) }}</p>
                        <p class="mt-1 text-sm">{{ __('platform.support.reason') }}: {{ $state['reason'] }}</p>
                    @endif
                </div>
            </div>

            <form method="post" action="{{ platform_route('kill-switch.update') }}" class="mt-6 space-y-4 border-t border-line pt-5"
                  @if (! $on) onsubmit="return confirm(@js(__('platform.kill_switch.confirm')))" @endif>
                @csrf
                <input type="hidden" name="active" value="{{ $on ? 0 : 1 }}">
                <x-ui.textarea name="reason" :label="__('platform.support.reason')" rows="3" required minlength="10" :placeholder="__('platform.kill_switch.reason_placeholder')" />
                <x-ui.button type="submit" :variant="$on ? 'primary' : 'danger'" icon="power">{{ $on ? __('platform.kill_switch.turn_off') : __('platform.kill_switch.turn_on') }}</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card class="self-start">
            <h2 class="mb-2 font-semibold">{{ __('platform.kill_switch.what_title') }}</h2>
            <p class="text-sm text-muted">{{ __('platform.kill_switch.what_text') }}</p>
            <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">{{ __('platform.kill_switch.not_built') }}</p>
        </x-ui.card>
    </div>
</x-layouts.platform>
