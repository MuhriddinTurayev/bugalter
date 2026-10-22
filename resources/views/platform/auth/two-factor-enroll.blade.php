<x-layouts.platform-guest :title="__('platform.two_factor.enroll_title')">
    <x-ui.card padding="p-6 sm:p-8">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('platform.two_factor.enroll_title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('platform.two_factor.enroll_text') }}</p>

        <ol class="mt-5 space-y-5 text-sm">
            <li>
                <p class="font-semibold">1. {{ __('platform.two_factor.step_scan') }}</p>
                <div class="mt-3 flex flex-col items-center gap-3 rounded-2xl bg-soft p-4">
                    <div class="rounded-xl bg-white p-2" role="img" aria-label="{{ __('platform.two_factor.qr_alt') }}">{!! $qr !!}</div>
                    <details class="w-full text-center">
                        <summary class="cursor-pointer text-xs font-semibold text-muted">{{ __('platform.two_factor.cant_scan') }}</summary>
                        <p class="mt-2 break-all font-mono text-xs tracking-wider">{{ $secret }}</p>
                    </details>
                </div>
            </li>
            <li>
                <p class="font-semibold">2. {{ __('platform.two_factor.step_code') }}</p>
                <form method="post" action="{{ platform_route('two-factor.confirm') }}" class="mt-3 space-y-3">
                    @csrf
                    <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="field text-center text-2xl tracking-[0.5em] tabular-nums {{ $errors->has('code') ? 'field-invalid' : '' }}" placeholder="000000" aria-label="{{ __('platform.two_factor.code') }}" autofocus required>
                    @error('code')<p class="text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                    <x-ui.button type="submit" variant="dark" class="w-full">{{ __('platform.two_factor.enable') }}</x-ui.button>
                </form>
            </li>
        </ol>

        @if ($canSkip)
            <form method="post" action="{{ platform_route('two-factor.skip') }}" class="mt-6 border-t border-line pt-5">
                @csrf
                <p class="mb-3 rounded-xl bg-red-50 px-3 py-2 text-xs font-medium text-red-700">{{ __('platform.two_factor.skip_warning') }}</p>
                <x-ui.button type="submit" variant="secondary" class="w-full !text-red-600">{{ __('platform.two_factor.skip') }}</x-ui.button>
            </form>
        @endif

        <form method="post" action="{{ platform_route('logout') }}" class="mt-4 text-center">@csrf
            <button class="text-xs text-muted hover:text-ink">{{ __('platform.nav.logout') }}</button>
        </form>
    </x-ui.card>
</x-layouts.platform-guest>
