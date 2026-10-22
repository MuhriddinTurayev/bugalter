@props(['title' => null])

@php
    // Language links work on company hosts, the platform panel and the root landing page.
    $localeUrl = function (string $code) {
        if (request()->routeIs('platform.*')) {
            return platform_route('locale', ['locale' => $code]);
        }

        return \App\Support\PlatformUrl::isCentralHost(request()) && ! config('app.single_company')
            ? url('/?lang='.$code)
            : route('locale', $code);
    };
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
<div class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
    <div class="mb-6 flex items-center justify-between">
        <span class="flex items-center gap-2.5">
            <span class="grid size-10 place-items-center rounded-full bg-brand-600 text-white">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 5v14M7 5h5.5a3.5 3.5 0 0 1 0 7H7m5.5 0a3.5 3.5 0 0 1 0 7H7"/></svg>
            </span>
            <span class="text-xl font-bold tracking-tight text-brand-700">{{ config('app.name') }}</span>
        </span>
        <div class="flex gap-1 rounded-full bg-white p-1 shadow-card">
            @foreach (\App\Support\Locale::supported() as $code => $name)
                <a href="{{ $localeUrl($code) }}" @class([
                    'rounded-full px-3 py-1.5 text-xs font-semibold transition',
                    'bg-brand-600 text-white' => app()->getLocale() === $code,
                    'text-muted hover:text-ink' => app()->getLocale() !== $code,
                ])>{{ $name }}</a>
            @endforeach
        </div>
    </div>
    {{ $slot }}
</div>
</body>
</html>
