@props(['name', 'label' => null, 'value' => null, 'required' => false, 'placeholder' => null])

@php
    $id = $attributes->get('id', $name);
    $current = (string) old($name, $value);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif</label>
    @endif
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        @required($required)
        {{ $attributes->except(['class', 'id'])->merge(['class' => 'field pr-8'.($errors->has($name) ? ' field-invalid' : '')]) }}
    >
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        {{ $slot }}
    </select>
    @error($name)<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
</div>
