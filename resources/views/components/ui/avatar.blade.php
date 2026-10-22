@props(['text', 'size' => 'size-9', 'tone' => 'brand'])

@php
    $tones = [
        'brand' => 'bg-brand-100 text-brand-700',
        'dark' => 'bg-ink text-white',
        'amber' => 'bg-amber-100 text-amber-700',
        'sky' => 'bg-sky-100 text-sky-700',
        'rose' => 'bg-rose-100 text-rose-700',
    ];
@endphp

<span {{ $attributes->merge(['class' => "grid $size shrink-0 place-items-center rounded-full text-xs font-bold ".($tones[$tone] ?? $tones['brand'])]) }}>{{ $text }}</span>
