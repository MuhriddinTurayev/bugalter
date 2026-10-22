<x-layouts.platform-guest :title="__('platform.two_factor.codes_title')">
    <x-ui.card padding="p-6 sm:p-8">
        <span class="mb-4 grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600"><x-ui.icon name="check" class="size-6" /></span>
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('platform.two_factor.enabled_title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('platform.two_factor.codes_text') }}</p>

        <ul class="mt-5 grid grid-cols-2 gap-2 rounded-2xl bg-soft p-4 font-mono text-sm">
            @foreach ($codes as $code)<li class="text-center tracking-wider">{{ $code }}</li>@endforeach
        </ul>
        <div class="mt-3 flex justify-center"><x-ui.copy :value="implode("\n", $codes)" :label="__('platform.two_factor.copy_codes')" /></div>
        <p class="mt-4 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">{{ __('platform.two_factor.codes_warning') }}</p>

        <x-ui.button :href="platform_route('dashboard')" variant="dark" class="mt-6 w-full">{{ __('platform.two_factor.continue') }}</x-ui.button>
    </x-ui.card>
</x-layouts.platform-guest>
