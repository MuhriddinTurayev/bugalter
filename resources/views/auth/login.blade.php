<x-layouts.guest :title="__('ui.login.title')">
    <x-ui.card padding="p-6 sm:p-8">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('ui.login.title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('app.tagline') }}</p>

        <form method="post" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf
            <x-ui.input name="login" :label="__('ui.login.login')" :placeholder="__('ui.login.login_placeholder')" autocomplete="username" autofocus required />
            <x-ui.input name="password" type="password" :label="__('ui.login.password')" autocomplete="current-password" required />

            <label class="flex items-center gap-2 text-sm text-muted">
                <input type="checkbox" name="remember" value="1" class="size-4 rounded border-line text-brand-600 focus:ring-brand-500">
                {{ __('ui.login.remember') }}
            </label>

            <x-ui.button type="submit" class="w-full">{{ __('ui.login.submit') }}</x-ui.button>
        </form>
    </x-ui.card>

    @if (app()->environment('local') && \App\Models\CompanySetting::query()->where('is_demo', true)->exists())
        <div class="mt-4 rounded-3xl bg-white/70 p-5 text-sm text-muted">
            <p class="font-semibold text-ink">{{ __('ui.login.demo_title') }}</p>
            <ul class="mt-2 space-y-1 font-mono text-xs">
                <li>owner@bugalter.test — {{ __('ui.roles.owner') }}</li>
                <li>accountant@bugalter.test — {{ __('ui.roles.chief_accountant') }}</li>
                <li>cashier@bugalter.test — {{ __('ui.roles.cashier') }}</li>
            </ul>
            <p class="mt-2 text-xs">{{ __('ui.login.demo_password') }}</p>
        </div>
    @endif
</x-layouts.guest>
