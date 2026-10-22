@props(['variant' => 'primary', 'href' => null, 'type' => 'button', 'icon' => null, 'size' => 'md'])

@php
    $variants = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:ring-brand-500/30',
        'dark' => 'bg-ink text-white hover:bg-black focus-visible:ring-ink/30',
        'secondary' => 'bg-white text-ink ring-1 ring-line hover:bg-soft focus-visible:ring-brand-500/30',
        'soft' => 'bg-brand-50 text-brand-700 hover:bg-brand-100 focus-visible:ring-brand-500/30',
        'ghost' => 'text-muted hover:bg-soft hover:text-ink focus-visible:ring-brand-500/30',
        'danger' => 'bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500/30',
    ];
    $sizes = ['sm' => 'px-3 py-1.5 text-xs', 'md' => 'px-4 py-2.5 text-sm'];
    $classes = 'inline-flex items-center justify-center gap-2 rounded-full font-semibold transition outline-none focus-visible:ring-4 disabled:opacity-50 disabled:pointer-events-none '
        .($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </button>
@endif
