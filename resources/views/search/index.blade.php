<x-layouts.app :title="__('ui.search.title')">
    <x-ui.page-header :title="__('ui.search.title')" />

    <form method="get" action="{{ route('search') }}" class="mb-6 flex max-w-xl gap-2" role="search">
        <input type="search" name="q" value="{{ $term }}" class="field" placeholder="{{ __('ui.nav.search_placeholder') }}" aria-label="{{ __('ui.common.search') }}" autofocus>
        <x-ui.button type="submit" variant="dark" icon="search">{{ __('ui.common.search') }}</x-ui.button>
    </form>

    @if ($term === '')
        <x-ui.card><x-ui.empty-state :title="__('ui.search.hint')" icon="search" /></x-ui.card>
    @elseif ($contractors->isEmpty() && $documents->isEmpty())
        <x-ui.card><x-ui.empty-state :title="__('ui.search.empty', ['term' => $term])" icon="search" /></x-ui.card>
    @else
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <x-ui.card padding="p-0">
                <h2 class="border-b border-line px-5 py-4 font-semibold">{{ __('ui.nav.contractors') }} <span class="text-muted">({{ $contractors->count() }})</span></h2>
                <ul class="divide-y divide-line">
                    @forelse ($contractors as $contractor)
                        <li><a href="{{ route('contractors.edit', $contractor) }}" class="flex items-center gap-3 px-5 py-3 transition hover:bg-soft"><x-ui.avatar :text="$contractor->initials()" /><div class="min-w-0"><p class="truncate font-semibold">{{ $contractor->name }}</p><p class="text-xs text-muted">{{ $contractor->stir }}</p></div></a></li>
                    @empty
                        <li class="px-5 py-6 text-center text-sm text-muted">{{ __('ui.search.none') }}</li>
                    @endforelse
                </ul>
            </x-ui.card>
            <x-ui.card padding="p-0">
                <h2 class="border-b border-line px-5 py-4 font-semibold">{{ __('ui.nav.documents') }} <span class="text-muted">({{ $documents->count() }})</span></h2>
                <ul class="divide-y divide-line">
                    @forelse ($documents as $document)
                        <li><a href="{{ route('documents.show', $document) }}" class="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-soft"><div class="min-w-0"><p class="font-semibold">{{ $document->number }}</p><p class="truncate text-xs text-muted">{{ $document->contractor->name }}</p></div><span class="amount text-sm font-semibold">{{ \App\Support\Money::format($document->total_minor) }}</span></a></li>
                    @empty
                        <li class="px-5 py-6 text-center text-sm text-muted">{{ __('ui.search.none') }}</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    @endif
</x-layouts.app>
