@props(['title' => null])

@php
    /** @var \App\Models\PlatformStaff $staff */
    $staff = auth('platform')->user();
    $active = fn (string ...$names) => collect($names)->contains(fn ($n) => request()->routeIs("platform.$n", "platform.path.$n"));

    $items = collect([
        ['label' => __('platform.nav.dashboard'), 'href' => platform_route('dashboard'), 'icon' => 'grid', 'active' => $active('dashboard'), 'show' => true],
        ['label' => __('platform.nav.companies'), 'href' => platform_route('companies.index'), 'icon' => 'building', 'active' => $active('companies.*', 'support.*'), 'show' => true],
        ['label' => __('platform.nav.plans'), 'href' => platform_route('plans.index'), 'icon' => 'receipt', 'active' => $active('plans.*'), 'show' => true],
        ['label' => __('platform.nav.staff'), 'href' => platform_route('staff.index'), 'icon' => 'users', 'active' => $active('staff.*'), 'show' => $staff->isSuperAdmin()],
        ['label' => __('platform.nav.tax_rules'), 'href' => platform_route('tax-rules'), 'icon' => 'calculator', 'active' => $active('tax-rules*'), 'show' => true],
        ['label' => __('platform.nav.integrations'), 'href' => platform_route('integrations'), 'icon' => 'link', 'active' => $active('integrations*'), 'show' => $staff->isSuperAdmin()],
        ['label' => __('platform.nav.audit'), 'href' => platform_route('audit'), 'icon' => 'clock', 'active' => $active('audit'), 'show' => true],
        ['label' => __('platform.nav.kill_switch'), 'href' => platform_route('kill-switch'), 'icon' => 'power', 'active' => $active('kill-switch*'), 'show' => $staff->isSuperAdmin()],
    ])->where('show', true)->values();

    $killSwitch = \App\Services\Platform\KillSwitch::state();
    $twoFactorSkipped = ! $staff->hasTwoFactor() && \App\Services\Platform\TwoFactor::skipAllowed();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ __('platform.brand') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">

{{-- Banners that must never be missed --}}
@if ($twoFactorSkipped)
    <div class="bg-red-600 px-4 py-2 text-center text-sm font-semibold text-white" role="alert">{{ __('platform.two_factor.local_banner') }}</div>
@endif
@if ($killSwitch['active'])
    <div class="bg-amber-500 px-4 py-2 text-center text-sm font-semibold text-ink" role="status">
        <x-ui.icon name="pause" class="mr-1 inline size-4" /> {{ __('platform.kill_switch.banner') }}
    </div>
@endif

<div class="mx-auto max-w-[1400px] px-3 pt-3 sm:px-6 sm:pt-5 lg:px-8">
    {{-- A deliberately different header from the company apps: dark, labelled "Platform admin". --}}
    <header class="rounded-[2rem] bg-ink px-3 py-2.5 text-white shadow-card sm:px-5">
        <div class="flex items-center gap-3">
            <a href="{{ platform_route('dashboard') }}" class="flex shrink-0 items-center gap-2.5">
                <span class="grid size-9 place-items-center rounded-full bg-white text-ink"><x-ui.icon name="shield" class="size-5" /></span>
                <span class="leading-tight">
                    <span class="block text-sm font-bold tracking-tight">{{ __('platform.brand') }}</span>
                    <span class="block text-[11px] font-medium text-white/60">{{ config('platform.domain') }}@if (app()->environment('local')) · {{ __('platform.common.local') }}@endif</span>
                </span>
            </a>

            <nav class="mx-auto hidden min-w-0 items-center gap-0.5 overflow-x-auto rounded-full bg-white/10 p-1 xl:flex" aria-label="{{ __('platform.nav.menu') }}">
                @foreach ($items as $item)
                    <a href="{{ $item['href'] }}" @class([
                        'whitespace-nowrap rounded-full px-3 py-2 text-sm font-semibold transition 2xl:px-4',
                        'bg-white text-ink' => $item['active'],
                        'text-white/70 hover:text-white' => ! $item['active'],
                    ]) @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-2 xl:ml-0">
                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                    <button type="button" class="flex items-center gap-2 rounded-full p-0.5 pr-2 transition hover:bg-white/10" @click="open = !open" :aria-expanded="open" aria-label="{{ __('platform.nav.profile') }}">
                        <x-ui.avatar :text="$staff->initials()" size="size-9" tone="brand" />
                        <span class="hidden text-left leading-tight sm:block">
                            <span class="block text-sm font-semibold">{{ $staff->name }}</span>
                            <span class="block text-[11px] text-white/60">{{ $staff->role->label() }}</span>
                        </span>
                        <x-ui.icon name="chevron-down" class="size-4 text-white/60" />
                    </button>
                    <div x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false" class="absolute right-0 z-40 mt-2 w-72 max-w-[calc(100vw-1.5rem)] rounded-3xl bg-white p-2 text-ink shadow-2xl ring-1 ring-line">
                        <div class="px-3 py-2.5">
                            <p class="truncate font-semibold">{{ $staff->name }}</p>
                            <p class="truncate text-xs text-muted">{{ $staff->email }}</p>
                        </div>
                        <div class="border-t border-line px-3 py-2.5">
                            <p class="label">{{ __('platform.nav.language') }}</p>
                            <div class="flex gap-1.5">
                                @foreach (\App\Support\Locale::supported() as $code => $name)
                                    <a href="{{ platform_route('locale', ['locale' => $code]) }}" @class([
                                        'flex-1 rounded-full px-3 py-1.5 text-center text-xs font-semibold transition',
                                        'bg-ink text-white' => app()->getLocale() === $code,
                                        'bg-soft text-muted hover:text-ink' => app()->getLocale() !== $code,
                                    ])>{{ $name }}</a>
                                @endforeach
                            </div>
                        </div>
                        <div class="border-t border-line pt-1">
                            <a href="{{ platform_route('profile') }}" class="flex items-center gap-2.5 rounded-2xl px-3 py-2.5 text-sm transition hover:bg-soft"><x-ui.icon name="lock" class="size-4 text-muted" /> {{ __('platform.nav.profile') }}</a>
                            <form method="post" action="{{ platform_route('logout') }}">@csrf
                                <button class="flex w-full items-center gap-2.5 rounded-2xl px-3 py-2.5 text-left text-sm transition hover:bg-soft"><x-ui.icon name="logout" class="size-4 text-muted" /> {{ __('platform.nav.logout') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Mobile navigation --}}
        <nav class="-mx-1 mt-2 flex gap-1 overflow-x-auto px-1 pb-1 xl:hidden" aria-label="{{ __('platform.nav.menu') }}">
            @foreach ($items as $item)
                <a href="{{ $item['href'] }}" @class([
                    'whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                    'bg-white text-ink' => $item['active'],
                    'bg-white/10 text-white/80' => ! $item['active'],
                ])>{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </header>

    <main class="min-w-0 pb-12 pt-6">
        <x-ui.alerts />
        {{ $slot }}
    </main>
</div>
</body>
</html>
