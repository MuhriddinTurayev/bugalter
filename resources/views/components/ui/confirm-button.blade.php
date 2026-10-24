@props(['message', 'variant' => 'ghost', 'icon' => null, 'size' => 'sm'])

{{-- @js() is not compiled inside a component tag's attributes, so the handler is built as a bound PHP expression. --}}
<x-ui.button type="submit" :variant="$variant" :icon="$icon" :size="$size" {{ $attributes }}
    :onclick="'return confirm('.\Illuminate\Support\Js::from($message).')'">{{ $slot }}</x-ui.button>
