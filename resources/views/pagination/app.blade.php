@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('ui.common.pagination') }}" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted">
            {{ __('ui.common.pagination_summary', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
        </p>

        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="grid size-9 place-items-center rounded-full text-muted/40" aria-disabled="true" aria-label="{{ __('ui.common.previous') }}"><x-ui.icon name="chevron-right" class="size-4 rotate-180" /></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="grid size-9 place-items-center rounded-full text-ink transition hover:bg-soft" aria-label="{{ __('ui.common.previous') }}"><x-ui.icon name="chevron-right" class="size-4 rotate-180" /></a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1 text-muted">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="grid size-9 place-items-center rounded-full bg-brand-600 text-sm font-semibold text-white" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="grid size-9 place-items-center rounded-full text-sm font-semibold text-ink transition hover:bg-soft" aria-label="{{ __('ui.common.go_to_page', ['page' => $page]) }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="grid size-9 place-items-center rounded-full text-ink transition hover:bg-soft" aria-label="{{ __('ui.common.next') }}"><x-ui.icon name="chevron-right" class="size-4" /></a>
            @else
                <span class="grid size-9 place-items-center rounded-full text-muted/40" aria-disabled="true" aria-label="{{ __('ui.common.next') }}"><x-ui.icon name="chevron-right" class="size-4" /></span>
            @endif
        </div>
    </nav>
@endif
