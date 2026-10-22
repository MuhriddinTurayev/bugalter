@php
    // The root host has no login. A language can be chosen with ?lang=uz|ru.
    $lang = request('lang');
    if (\App\Support\Locale::isSupported($lang)) {
        app()->setLocale($lang);
    }
@endphp
<x-layouts.guest :title="config('app.name')">
    <x-ui.card padding="p-8" class="text-center">
        <span class="mx-auto mb-5 grid size-14 place-items-center rounded-full bg-brand-600 text-white">
            <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 5v14M7 5h5.5a3.5 3.5 0 0 1 0 7H7m5.5 0a3.5 3.5 0 0 1 0 7H7"/></svg>
        </span>
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('app.welcome.heading') }}</h1>
        <p class="mt-2 text-sm text-muted">{{ __('app.welcome.body') }}</p>
        <p class="mt-6 rounded-2xl bg-soft px-4 py-3 text-sm">{{ __('ui.landing.company_hint', ['domain' => config('platform.domain')]) }}</p>
        @if (\App\Support\PlatformUrl::pathFallbackEnabled())
            <p class="mt-3 text-xs text-muted">
                {{ __('ui.landing.admin_hint') }}
                <a href="{{ url('/platform/login') }}" class="font-semibold text-brand-700 hover:underline">{{ __('ui.landing.admin_link') }}</a>
            </p>
        @endif
    </x-ui.card>
</x-layouts.guest>
