@props(['title' => null])

@php
    $user = auth()->user();
    $company = app(\App\Services\CurrentCompany::class);

    $items = collect([
        ['key' => 'home', 'route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'grid', 'label' => __('ui.nav.home'), 'show' => true],
        ['key' => 'documents', 'route' => 'documents.index', 'match' => 'documents.*', 'icon' => 'document', 'label' => __('ui.nav.documents'), 'show' => true],
        ['key' => 'bank', 'route' => 'bank.index', 'match' => 'bank.*', 'icon' => 'wallet', 'label' => __('ui.nav.bank'), 'show' => true],
        ['key' => 'contractors', 'route' => 'contractors.index', 'match' => 'contractors.*', 'icon' => 'users', 'label' => __('ui.nav.contractors'), 'show' => true],
        ['key' => 'reports', 'route' => 'reports', 'match' => 'reports', 'icon' => 'chart', 'label' => __('ui.nav.reports'), 'show' => $user->can('view-reports')],
        ['key' => 'history', 'route' => 'history', 'match' => 'history', 'icon' => 'clock', 'label' => __('ui.nav.history'), 'show' => true],
    ])->where('show', true)->map(fn (array $i) => $i + ['active' => request()->routeIs($i['match']), 'href' => route($i['route'])])->values();

    $pill = collect(['home', 'documents', 'reports', 'history', 'contractors'])
        ->map(fn (string $key) => $items->firstWhere('key', $key))
        ->filter();
    $railTop = $items->whereIn('key', ['home', 'documents', 'bank', 'contractors']);
    $railMid = $items->whereIn('key', ['reports', 'history']);

    $unread = $user->unreadNotifications()->latest()->limit(8)->get();
    $unreadCount = $user->unreadNotifications()->count();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
<div class="mx-auto max-w-[1520px] px-3 pt-3 sm:px-6 sm:pt-5 lg:px-8">

    {{-- Top bar --}}
    <header class="flex items-center gap-3 rounded-full bg-white px-3 py-2.5 shadow-card sm:px-5">
        <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2.5" aria-label="{{ config('app.name') }}">
            <span class="grid size-9 place-items-center rounded-full bg-brand-600 text-white">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 5v14M7 5h5.5a3.5 3.5 0 0 1 0 7H7m5.5 0a3.5 3.5 0 0 1 0 7H7"/></svg>
            </span>
            <span class="hidden text-lg font-bold tracking-tight text-brand-700 sm:block">{{ config('app.name') }}</span>
        </a>

        <nav class="mx-auto hidden items-center gap-1 rounded-full bg-soft p-1 lg:flex" aria-label="{{ __('ui.nav.menu') }}">
            @foreach ($pill as $item)
                <a href="{{ $item['href'] }}"
                   @class([
                       'rounded-full px-5 py-2 text-sm font-semibold transition',
                       'bg-white text-ink shadow-sm' => $item['active'],
                       'text-muted hover:text-ink' => ! $item['active'],
                   ])
                   @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-1.5 sm:gap-2 lg:ml-0">
            {{-- Search --}}
            <form action="{{ route('search') }}" method="get" role="search" class="relative hidden md:block">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <input type="search" name="q" value="{{ request()->routeIs('search') ? request('q') : '' }}" placeholder="{{ __('ui.nav.search_placeholder') }}"
                       class="w-44 rounded-full bg-soft py-2 pl-9 pr-3 text-sm outline-none transition placeholder:text-muted focus:w-60 focus:ring-4 focus:ring-brand-500/15" aria-label="{{ __('ui.common.search') }}">
            </form>
            <a href="{{ route('search') }}" class="grid size-10 place-items-center rounded-full bg-soft text-ink md:hidden" aria-label="{{ __('ui.common.search') }}">
                <x-ui.icon name="search" class="size-[18px]" />
            </a>

            {{-- Notifications --}}
            <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                <button type="button" class="relative grid size-10 place-items-center rounded-full bg-soft text-ink transition hover:bg-line" @click="open = !open" :aria-expanded="open" aria-label="{{ __('ui.nav.notifications') }}">
                    <x-ui.icon name="bell" class="size-[18px]" />
                    @if ($unreadCount > 0)
                        <span class="absolute right-1.5 top-1.5 grid min-w-4 place-items-center rounded-full bg-brand-600 px-1 text-[10px] font-bold leading-4 text-white">{{ $unreadCount }}</span>
                    @endif
                </button>
                <div x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false" class="absolute right-0 z-40 mt-2 w-80 max-w-[calc(100vw-1.5rem)] rounded-3xl bg-white p-2 shadow-2xl ring-1 ring-line">
                    <div class="flex items-center justify-between px-3 py-2">
                        <p class="text-sm font-semibold">{{ __('ui.nav.notifications') }}</p>
                        @if ($unreadCount > 0)
                            <form method="post" action="{{ route('notifications.read') }}">@csrf
                                <button class="text-xs font-semibold text-brand-700 hover:underline">{{ __('ui.nav.mark_read') }}</button>
                            </form>
                        @endif
                    </div>
                    @forelse ($unread as $notification)
                        <a href="{{ $notification->data['url'] ?? '#' }}" class="block rounded-2xl px-3 py-2.5 text-sm transition hover:bg-soft">
                            <p>{{ __($notification->data['key'], $notification->data['params'] ?? []) }}</p>
                            <p class="mt-0.5 text-xs text-muted">{{ $notification->created_at->format('d.m.Y H:i') }}</p>
                        </a>
                    @empty
                        <p class="px-3 py-6 text-center text-sm text-muted">{{ __('ui.nav.no_notifications') }}</p>
                    @endforelse
                </div>
            </div>

            {{-- Profile --}}
            <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                <button type="button" class="flex items-center gap-1.5 rounded-full p-0.5 transition hover:bg-soft" @click="open = !open" :aria-expanded="open" aria-label="{{ __('ui.nav.profile') }}">
                    <x-ui.avatar :text="$user->initials()" size="size-9" tone="dark" />
                    <x-ui.icon name="chevron-down" class="mr-1 hidden size-4 text-muted sm:block" />
                </button>
                <div x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false" class="absolute right-0 z-40 mt-2 w-72 max-w-[calc(100vw-1.5rem)] rounded-3xl bg-white p-2 shadow-2xl ring-1 ring-line">
                    <div class="px-3 py-2.5">
                        <p class="truncate font-semibold">{{ $user->name }}</p>
                        <p class="truncate text-xs text-muted">{{ $user->role->label() }} · {{ $company->name() }}</p>
                    </div>
                    <div class="border-t border-line px-3 py-2.5">
                        <p class="label">{{ __('ui.nav.language') }}</p>
                        <div class="flex gap-1.5">
                            @foreach (\App\Support\Locale::supported() as $code => $name)
                                <a href="{{ route('locale', $code) }}" @class([
                                    'flex-1 rounded-full px-3 py-1.5 text-center text-xs font-semibold transition',
                                    'bg-brand-600 text-white' => app()->getLocale() === $code,
                                    'bg-soft text-muted hover:text-ink' => app()->getLocale() !== $code,
                                ])>{{ $name }}</a>
                            @endforeach
                        </div>
                    </div>
                    <div class="border-t border-line pt-1">
                        @can('manage-settings')
                            <a href="{{ route('settings.company') }}" class="flex items-center gap-2.5 rounded-2xl px-3 py-2.5 text-sm transition hover:bg-soft"><x-ui.icon name="cog" class="size-4 text-muted" /> {{ __('ui.nav.settings') }}</a>
                        @endcan
                        @can('view-reports')
                            <a href="{{ route('reports') }}" class="flex items-center gap-2.5 rounded-2xl px-3 py-2.5 text-sm transition hover:bg-soft lg:hidden"><x-ui.icon name="chart" class="size-4 text-muted" /> {{ __('ui.nav.reports') }}</a>
                        @endcan
                        <form method="post" action="{{ route('logout') }}">@csrf
                            <button class="flex w-full items-center gap-2.5 rounded-2xl px-3 py-2.5 text-left text-sm transition hover:bg-soft"><x-ui.icon name="logout" class="size-4 text-muted" /> {{ __('ui.nav.logout') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="mt-5 flex gap-5 lg:gap-6">
        {{-- Icon rail --}}
        <aside class="sticky top-5 hidden h-[calc(100vh-8.5rem)] w-[4.5rem] shrink-0 flex-col justify-between self-start lg:flex">
            <div class="space-y-4">
                @foreach ([$railTop, $railMid] as $group)
                    <nav class="flex flex-col items-center gap-2 rounded-full bg-white p-2 shadow-card" aria-label="{{ __('ui.nav.menu') }}">
                        @foreach ($group as $item)
                            <a href="{{ $item['href'] }}" title="{{ $item['label'] }}" aria-label="{{ $item['label'] }}"
                               @class([
                                   'grid size-12 place-items-center rounded-full transition',
                                   'bg-brand-600 text-white shadow-md shadow-brand-600/30' => $item['active'],
                                   'text-muted hover:bg-soft hover:text-ink' => ! $item['active'],
                               ])
                               @if ($item['active']) aria-current="page" @endif>
                                <x-ui.icon :name="$item['icon']" class="size-[22px]" />
                            </a>
                        @endforeach
                    </nav>
                @endforeach
            </div>
            <div class="flex flex-col items-center gap-2 rounded-full bg-white p-2 shadow-card">
                @can('manage-settings')
                    <a href="{{ route('settings.company') }}" title="{{ __('ui.nav.settings') }}" aria-label="{{ __('ui.nav.settings') }}"
                       @class([
                           'grid size-12 place-items-center rounded-full transition',
                           'bg-brand-600 text-white' => request()->routeIs('settings.*'),
                           'text-muted hover:bg-soft hover:text-ink' => ! request()->routeIs('settings.*'),
                       ])><x-ui.icon name="cog" class="size-[22px]" /></a>
                @endcan
                <form method="post" action="{{ route('logout') }}">@csrf
                    <button class="grid size-12 place-items-center rounded-full text-muted transition hover:bg-soft hover:text-ink" title="{{ __('ui.nav.logout') }}" aria-label="{{ __('ui.nav.logout') }}"><x-ui.icon name="logout" class="size-[22px]" /></button>
                </form>
            </div>
        </aside>

        <main class="min-w-0 flex-1 pb-28 lg:pb-10">
            <x-ui.alerts />
            {{ $slot }}
        </main>
    </div>
</div>

{{-- Mobile bottom navigation --}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white/95 px-2 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden" aria-label="{{ __('ui.nav.menu') }}">
    <ul class="mx-auto flex max-w-md items-stretch justify-between">
        @foreach ($items as $item)
            <li class="flex-1">
                <a href="{{ $item['href'] }}" @class([
                    'flex flex-col items-center gap-0.5 py-2 text-[11px] font-semibold transition',
                    'text-brand-700' => $item['active'],
                    'text-muted' => ! $item['active'],
                ]) @if ($item['active']) aria-current="page" @endif>
                    <span @class(['grid h-7 w-12 place-items-center rounded-full transition', 'bg-brand-100' => $item['active']])>
                        <x-ui.icon :name="$item['icon']" class="size-5" />
                    </span>
                    {{ $item['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
</body>
</html>
