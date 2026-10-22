<x-layouts.guest :title="__('ui.errors.404_title')">
    <x-ui.card padding="p-8" class="text-center">
        <span class="mx-auto mb-4 grid size-14 place-items-center rounded-full bg-brand-50 text-brand-600"><x-ui.icon name="search" class="size-6" /></span>
        <h1 class="text-2xl font-semibold">{{ __('ui.errors.404_title') }}</h1>
        <p class="mt-2 text-sm text-muted">{{ __('ui.errors.404_text') }}</p>
        <x-ui.button :href="url('/')" class="mt-6">{{ __('ui.errors.home') }}</x-ui.button>
    </x-ui.card>
</x-layouts.guest>
