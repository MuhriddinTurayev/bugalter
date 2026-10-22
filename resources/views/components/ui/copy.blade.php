@props(['value', 'label' => null])

{{-- Copies a value to the clipboard and confirms it for a moment. --}}
<button type="button"
        x-data="{ copied: false, value: @js($value) }"
        @click="navigator.clipboard.writeText(value).then(() => { copied = true; setTimeout(() => copied = false, 1800) })"
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full bg-soft px-3 py-1.5 text-xs font-semibold text-ink transition hover:bg-brand-50']) }}
        aria-label="{{ $label ?? __('platform.common.copy') }}">
    <x-ui.icon name="copy" class="size-3.5" x-show="!copied" />
    <x-ui.icon name="check" class="size-3.5 text-brand-600" x-cloak x-show="copied" />
    <span x-text="copied ? @js(__('platform.common.copied')) : @js($label ?? __('platform.common.copy'))"></span>
</button>
