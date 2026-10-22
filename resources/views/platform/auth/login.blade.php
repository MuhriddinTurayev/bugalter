<x-layouts.platform-guest :title="__('platform.login.title')">
    <x-ui.card padding="p-6 sm:p-8">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('platform.login.title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('platform.login.subtitle') }}</p>

        <form method="post" action="{{ platform_route('login.store') }}" class="mt-6 space-y-4">
            @csrf
            <x-ui.input name="email" type="email" :label="__('platform.fields.email')" autocomplete="username" autofocus required />
            <x-ui.input name="password" type="password" :label="__('platform.fields.password')" autocomplete="current-password" required />
            <x-ui.button type="submit" variant="dark" class="w-full">{{ __('platform.login.submit') }}</x-ui.button>
        </form>
    </x-ui.card>
    <p class="mt-4 text-center text-xs text-muted">{{ __('platform.login.notice') }}</p>
</x-layouts.platform-guest>
