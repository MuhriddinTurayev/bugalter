@props(['title' => null])

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
<div class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
    <div class="mb-6 flex items-center justify-between">
        <span class="flex items-center gap-2.5">
            <span class="grid size-10 place-items-center rounded-full bg-ink text-white"><x-ui.icon name="shield" class="size-5" /></span>
            <span class="text-lg font-bold tracking-tight">{{ __('platform.brand') }}</span>
        </span>
        <div class="flex gap-1 rounded-full bg-white p-1 shadow-card">
            @foreach (\App\Support\Locale::supported() as $code => $name)
                <a href="{{ platform_route('locale', ['locale' => $code]) }}" @class([
                    'rounded-full px-3 py-1.5 text-xs font-semibold transition',
                    'bg-ink text-white' => app()->getLocale() === $code,
                    'text-muted hover:text-ink' => app()->getLocale() !== $code,
                ])>{{ $name }}</a>
            @endforeach
        </div>
    </div>

    @if (\App\Services\Platform\TwoFactor::skipAllowed())
        <div class="mb-4 rounded-2xl bg-red-600 px-4 py-2.5 text-center text-sm font-semibold text-white" role="alert">{{ __('platform.two_factor.local_banner') }}</div>
    @endif

    {{ $slot }}
</div>
</body>
</html>
