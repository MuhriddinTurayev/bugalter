<x-layouts.platform :title="__('platform.nav.profile')">
    <x-ui.page-header :title="__('platform.profile.title')" :subtitle="$staff->name.' · '.$staff->role->label()" />

    @if ($codes)
        <x-ui.card class="mb-5 ring-2 ring-brand-300">
            <h2 class="font-semibold">{{ __('platform.two_factor.codes_title') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('platform.two_factor.codes_text') }}</p>
            <ul class="mt-4 grid max-w-md grid-cols-2 gap-2 rounded-2xl bg-soft p-4 font-mono text-sm">@foreach ($codes as $code)<li class="text-center tracking-wider">{{ $code }}</li>@endforeach</ul>
            <div class="mt-3"><x-ui.copy :value="implode("\n", $codes)" :label="__('platform.two_factor.copy_codes')" /></div>
        </x-ui.card>
    @endif

    <div class="grid grid-cols-1 max-w-5xl gap-5 lg:grid-cols-2">
        <x-ui.card>
            <h2 class="mb-4 font-semibold">{{ __('platform.profile.change_password') }}</h2>
            <form method="post" action="{{ platform_route('profile.password') }}" class="space-y-4">
                @csrf @method('PUT')
                <x-ui.input name="current_password" type="password" :label="__('platform.profile.current_password')" autocomplete="current-password" required />
                <x-ui.input name="password" type="password" :label="__('platform.profile.new_password')" autocomplete="new-password" :hint="__('platform.profile.password_hint')" required />
                <x-ui.input name="password_confirmation" type="password" :label="__('platform.profile.confirm_password')" autocomplete="new-password" required />
                <x-ui.button type="submit" variant="dark">{{ __('platform.common.save') }}</x-ui.button>
            </form>
        </x-ui.card>

        <div class="space-y-5">
            <x-ui.card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">{{ __('platform.profile.two_factor') }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ __('platform.profile.two_factor_text') }}</p>
                    </div>
                    @if ($staff->hasTwoFactor())<x-ui.badge tone="success">{{ __('platform.staff.two_factor_on') }}</x-ui.badge>@else<x-ui.badge tone="danger">{{ __('platform.staff.two_factor_off') }}</x-ui.badge>@endif
                </div>

                @if ($staff->hasTwoFactor())
                    <p class="mt-3 text-sm">{{ __('platform.profile.codes_left', ['count' => $remaining]) }}</p>
                    <form method="post" action="{{ platform_route('profile.recovery-codes') }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.input name="current_password" type="password" :label="__('platform.profile.current_password')" required />
                        <x-ui.button type="submit" variant="secondary" size="sm">{{ __('platform.profile.regenerate') }}</x-ui.button>
                    </form>

                    <form method="post" action="{{ platform_route('profile.two-factor.reset') }}" class="mt-5 space-y-3 border-t border-line pt-5">
                        @csrf
                        <p class="text-sm font-semibold">{{ __('platform.profile.new_device') }}</p>
                        <p class="text-xs text-muted">{{ __('platform.profile.new_device_text') }}</p>
                        <x-ui.input name="current_password" type="password" :label="__('platform.profile.current_password')" required />
                        <x-ui.input name="code" inputmode="numeric" maxlength="6" :label="__('platform.two_factor.code')" required />
                        <x-ui.button type="submit" variant="secondary" size="sm">{{ __('platform.profile.reenroll') }}</x-ui.button>
                    </form>
                @else
                    <x-ui.button :href="platform_route('two-factor.enroll')" variant="dark" size="sm" class="mt-3">{{ __('platform.two_factor.enable') }}</x-ui.button>
                @endif
                <p class="mt-4 text-xs text-muted">{{ __('platform.profile.mandatory') }}</p>
            </x-ui.card>
        </div>
    </div>
</x-layouts.platform>
