@props(['empty' => 'false', 'emptyTitle' => null, 'emptyText' => null, 'skeleton' => 'h-40', 'emptyIcon' => 'chart'])

{{-- Loading skeleton --}}
<div x-show="loading" class="space-y-3" aria-busy="true">
    <div class="skeleton h-5 w-1/3"></div>
    <div class="skeleton {{ $skeleton }} w-full"></div>
</div>

{{-- Error with retry --}}
<div x-cloak x-show="error && !loading" class="flex flex-col items-center gap-3 py-8 text-center" role="alert">
    <span class="grid size-11 place-items-center rounded-full bg-red-50 text-red-600"><x-ui.icon name="warning" class="size-5" /></span>
    <p class="text-sm text-muted">{{ __('ui.dashboard.load_error') }}</p>
    <x-ui.button variant="secondary" size="sm" @click="load()">{{ __('ui.common.retry') }}</x-ui.button>
</div>

{{-- Empty --}}
<div x-cloak x-show="!loading && !error && data && ({{ $empty }})">
    <x-ui.empty-state :title="$emptyTitle ?? __('ui.dashboard.empty_title')" :text="$emptyText ?? __('ui.dashboard.empty_text')" :icon="$emptyIcon" class="py-8" />
</div>

{{-- Content --}}
<template x-if="!loading && !error && data && !({{ $empty }})">
    <div>{{ $slot }}</div>
</template>
