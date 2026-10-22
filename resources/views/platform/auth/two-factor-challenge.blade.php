<x-layouts.platform-guest :title="__('platform.two_factor.challenge_title')">
    <x-ui.card padding="p-6 sm:p-8" x-data="{ recovery: false }">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('platform.two_factor.challenge_title') }}</h1>
        <p class="mt-1 text-sm text-muted" x-show="!recovery">{{ __('platform.two_factor.challenge_text') }}</p>
        <p class="mt-1 text-sm text-muted" x-cloak x-show="recovery">{{ __('platform.two_factor.recovery_text') }}</p>

        <form method="post" action="{{ platform_route('two-factor.verify') }}" class="mt-6 space-y-4">
            @csrf
            <div x-show="!recovery">
                <label for="code" class="label">{{ __('platform.two_factor.code') }}</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="field text-center text-2xl tracking-[0.5em] tabular-nums {{ $errors->has('code') ? 'field-invalid' : '' }}" :disabled="recovery" autofocus>
            </div>
            <div x-cloak x-show="recovery">
                <label for="recovery_code" class="label">{{ __('platform.two_factor.recovery_code') }}</label>
                <input id="recovery_code" name="recovery_code" autocomplete="off" class="field font-mono" :disabled="!recovery" placeholder="xxxxx-xxxxx">
            </div>
            @error('code')<p class="text-xs font-medium text-red-600">{{ $message }}</p>@enderror

            <x-ui.button type="submit" variant="dark" class="w-full">{{ __('platform.two_factor.verify') }}</x-ui.button>
            <button type="button" class="w-full text-center text-sm font-semibold text-muted hover:text-ink" @click="recovery = !recovery"
                    x-text="recovery ? @js(__('platform.two_factor.use_app')) : @js(__('platform.two_factor.use_recovery'))"></button>
        </form>

        <form method="post" action="{{ platform_route('logout') }}" class="mt-4 text-center">@csrf
            <button class="text-xs text-muted hover:text-ink">{{ __('platform.nav.logout') }}</button>
        </form>
    </x-ui.card>
</x-layouts.platform-guest>
