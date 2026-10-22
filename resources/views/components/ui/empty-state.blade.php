@props(['title', 'text' => null, 'icon' => 'document'])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="mb-4 grid size-14 place-items-center rounded-full bg-brand-50 text-brand-600">
        <x-ui.icon :name="$icon" class="size-6" />
    </span>
    <p class="font-semibold">{{ $title }}</p>
    @if ($text)<p class="mt-1 max-w-sm text-sm text-muted">{{ $text }}</p>@endif
    @if (trim($slot))<div class="mt-4">{{ $slot }}</div>@endif
</div>
