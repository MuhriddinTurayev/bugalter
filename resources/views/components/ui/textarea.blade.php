@props(['name', 'label' => null, 'value' => null, 'rows' => 3])

@php $id = $attributes->get('id', $name); @endphp

<div {{ $attributes->only('class') }}>
    @if ($label)<label for="{{ $id }}" class="label">{{ $label }}</label>@endif
    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        {{ $attributes->except(['class', 'id'])->merge(['class' => 'field'.($errors->has($name) ? ' field-invalid' : '')]) }}
    >{{ old($name, $value) }}</textarea>
    @error($name)<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
</div>
