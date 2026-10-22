<x-layouts.guest :title="__('ui.invitation.title')">
    <x-ui.card padding="p-6 sm:p-8">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('ui.invitation.title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('ui.invitation.subtitle', ['company' => $company, 'name' => $user->name]) }}</p>

        <form method="post" action="{{ route('invitation.accept', $token) }}" class="mt-6 space-y-4">
            @csrf
            <x-ui.input name="password" type="password" :label="__('ui.login.password')" autocomplete="new-password" :hint="__('ui.invitation.hint')" required autofocus />
            <x-ui.input name="password_confirmation" type="password" :label="__('ui.invitation.confirm')" autocomplete="new-password" required />
            <x-ui.button type="submit" class="w-full">{{ __('ui.invitation.submit') }}</x-ui.button>
        </form>
    </x-ui.card>
</x-layouts.guest>
