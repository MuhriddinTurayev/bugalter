<x-layouts.platform-guest :title="__('platform.staff.accept_title')">
    <x-ui.card padding="p-6 sm:p-8">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('platform.staff.accept_title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('platform.staff.accept_text', ['name' => $staff->name]) }}</p>

        <form method="post" action="{{ platform_route('invitation.accept', ['token' => $token]) }}" class="mt-6 space-y-4">
            @csrf
            <x-ui.input name="password" type="password" :label="__('platform.fields.password')" autocomplete="new-password" :hint="__('platform.profile.password_hint')" required autofocus />
            <x-ui.input name="password_confirmation" type="password" :label="__('platform.profile.confirm_password')" autocomplete="new-password" required />
            <x-ui.button type="submit" variant="dark" class="w-full">{{ __('platform.staff.accept_submit') }}</x-ui.button>
        </form>
    </x-ui.card>
</x-layouts.platform-guest>
