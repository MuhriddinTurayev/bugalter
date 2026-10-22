@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'hint' => null, 'required' => false])

@php
    $id = $attributes->get('id', $name);
    $dotted = str_replace(['[', ']'], ['.', ''], $name);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif</label>
    @endif
    <input
        id="{{ $id }}"
        type="{{ $type }}"
        name="{{ $name }}"
        value="{{ old($dotted, $value) }}"
        @required($required)
        {{ $attributes->except(['class', 'id'])->merge(['class' => 'field'.($errors->has($dotted) ? ' field-invalid' : '')]) }}
    >
    @if ($hint)<p class="mt-1 text-xs text-muted">{{ $hint }}</p>@endif
    @error($dotted)<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
</div>
