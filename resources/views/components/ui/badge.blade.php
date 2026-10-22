@props(['tone' => 'neutral'])

@php
    $tones = [
        'neutral' => 'bg-soft text-muted',
        'success' => 'bg-brand-100 text-brand-700',
        'info' => 'bg-sky-100 text-sky-700',
        'warning' => 'bg-amber-100 text-amber-700',
        'danger' => 'bg-red-100 text-red-700',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold '.($tones[$tone] ?? $tones['neutral'])]) }}>{{ $slot }}</span>
