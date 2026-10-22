@props(['message', 'variant' => 'ghost', 'icon' => null, 'size' => 'sm'])

<x-ui.button type="submit" :variant="$variant" :icon="$icon" :size="$size" {{ $attributes }}
    onclick="return confirm(@js($message))">{{ $slot }}</x-ui.button>
