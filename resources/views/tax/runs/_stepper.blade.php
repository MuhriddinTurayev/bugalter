{{-- The five steps of "prepare reports automatically"; $current is 1..5. --}}
<ol class="mb-5 flex flex-wrap gap-2">
    @foreach ([1 => 'company', 2 => 'analysis', 3 => 'review', 4 => 'approval', 5 => 'submission'] as $number => $key)
        <li @class([
            'inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-xs font-semibold',
            'bg-brand-700 text-white' => $number === $current,
            'bg-white text-brand-700 ring-1 ring-brand-100' => $number < $current,
            'bg-white text-muted ring-1 ring-line' => $number > $current,
        ])>
            <span @class(['grid size-5 place-items-center rounded-full text-[11px]', 'bg-white/20' => $number === $current, 'bg-brand-50' => $number < $current, 'bg-soft' => $number > $current])>
                @if ($number < $current)<x-ui.icon name="check" class="size-3" />@else{{ $number }}@endif
            </span>
            {{ __('tax.workflow.stepper.'.$key) }}
        </li>
    @endforeach
</ol>
