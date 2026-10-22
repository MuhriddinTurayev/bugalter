@props(['items'])

<nav class="mb-6 inline-flex max-w-full gap-1 overflow-x-auto rounded-full bg-white p-1 shadow-card" aria-label="{{ __('ui.nav.menu') }}">
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}"
           @class([
               'whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold transition',
               'bg-brand-600 text-white' => $item['active'],
               'text-muted hover:bg-soft hover:text-ink' => ! $item['active'],
           ])
           @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
</nav>
