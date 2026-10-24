@if (session('status'))
    <div x-data="{ show: true }" x-show="show" x-transition class="mb-5 flex items-start gap-3 rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800 ring-1 ring-brand-200" role="status">
        <x-ui.icon name="check" class="mt-0.5 size-4 shrink-0" />
        <div class="grow">
            <p class="font-medium">{{ session('status') }}</p>
            @foreach ((array) session('import_errors', []) as $message)
                <p class="mt-1 text-xs text-amber-700">{{ $message }}</p>
            @endforeach
        </div>
        <button type="button" class="text-brand-700/60 hover:text-brand-800" @click="show = false" aria-label="{{ __('ui.common.close') }}">
            <x-ui.icon name="x" class="size-4" />
        </button>
    </div>
@endif

@if (session('error'))
    <div class="mb-5 flex items-start gap-3 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200" role="alert">
        <x-ui.icon name="warning" class="mt-0.5 size-4 shrink-0" />
        <p class="font-medium">{{ session('error') }}</p>
    </div>
@endif

@if ($errors->any())
    @php
        // Errors that belong to no form field (a limit, a refused action) are spelled out here.
        $general = collect(['limit', 'status', 'action', 'submission', 'ai', 'support'])
            ->flatMap(fn (string $key) => $errors->get($key))
            ->unique();
    @endphp
    <div class="mb-5 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200" role="alert">
        <p class="font-semibold">{{ $general->isNotEmpty() ? $general->first() : __('ui.common.fix_errors') }}</p>
        @foreach ($general->skip(1) as $message)
            <p class="mt-1">{{ $message }}</p>
        @endforeach
    </div>
@endif
