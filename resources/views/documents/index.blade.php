<x-layouts.app :title="__('ui.documents.title')">
    <x-ui.page-header :title="__('ui.documents.title')" :subtitle="__('ui.documents.subtitle')">
        <x-slot:actions>
            @can('create', \App\Models\Document::class)
                <x-ui.button :href="route('documents.create', ['type' => 'act'])" variant="secondary" icon="plus">{{ __('ui.documents.new_act') }}</x-ui.button>
                <x-ui.button :href="route('documents.create', ['type' => 'invoice'])" icon="plus">{{ __('ui.documents.new_invoice') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card padding="p-0">
        <form method="get" class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-4">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ui.documents.search') }}" class="field" aria-label="{{ __('ui.common.search') }}">
            <select name="type" class="field" aria-label="{{ __('ui.documents.fields.type') }}">
                <option value="">{{ __('ui.documents.all_types') }}</option>
                @foreach (\App\Enums\DocumentType::cases() as $case)
                    <option value="{{ $case->value }}" @selected($filters['type'] === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
            <select name="status" class="field" aria-label="{{ __('ui.common.status') }}">
                <option value="">{{ __('ui.documents.all_statuses') }}</option>
                @foreach (\App\Enums\DocumentStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($filters['status'] === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
            <select name="direction" class="field" aria-label="{{ __('ui.documents.fields.direction') }}">
                <option value="">{{ __('ui.documents.all_directions') }}</option>
                <option value="out" @selected($filters['direction'] === 'out')>{{ __('ui.documents.directions.out') }}</option>
                <option value="in" @selected($filters['direction'] === 'in')>{{ __('ui.documents.directions.in') }}</option>
            </select>
            <select name="contractor_id" class="field lg:col-span-1" aria-label="{{ __('ui.documents.fields.contractor') }}">
                <option value="">{{ __('ui.documents.all_contractors') }}</option>
                @foreach ($contractors as $contractor)
                    <option value="{{ $contractor->id }}" @selected((int) request('contractor_id') === $contractor->id)>{{ $contractor->name }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="field" aria-label="{{ __('ui.common.from') }}">
            <input type="date" name="to" value="{{ request('to') }}" class="field" aria-label="{{ __('ui.common.to') }}">
            <div class="flex gap-2">
                <x-ui.button type="submit" variant="dark">{{ __('ui.common.filter') }}</x-ui.button>
                @if (request()->hasAny(['q', 'type', 'status', 'direction', 'contractor_id', 'from', 'to']))
                    <x-ui.button :href="route('documents.index')" variant="ghost">{{ __('ui.common.reset') }}</x-ui.button>
                @endif
            </div>
        </form>

        @if ($documents->isEmpty())
            <x-ui.empty-state :title="__('ui.documents.empty')" :text="__('ui.documents.empty_text')" icon="document" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('ui.documents.fields.number') }}</th>
                        <th class="th">{{ __('ui.documents.fields.contractor') }}</th>
                        <th class="th">{{ __('ui.documents.fields.issue_date') }}</th>
                        <th class="th">{{ __('ui.documents.fields.due_date') }}</th>
                        <th class="th text-right">{{ __('ui.documents.fields.total') }}</th>
                        <th class="th">{{ __('ui.common.status') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($documents as $document)
                            <tr class="transition hover:bg-soft/60">
                                <td class="td">
                                    <a href="{{ route('documents.show', $document) }}" class="font-semibold hover:text-brand-700">{{ $document->number }}</a>
                                    <p class="text-xs text-muted">{{ $document->type->label() }} · {{ __('ui.documents.directions.'.$document->direction) }}</p>
                                </td>
                                <td class="td">{{ $document->contractor->name }}</td>
                                <td class="td tabular-nums">{{ $document->issue_date->format('d.m.Y') }}</td>
                                <td class="td tabular-nums {{ $document->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ $document->due_date?->format('d.m.Y') ?? '—' }}</td>
                                <td class="td amount text-right font-semibold">{{ \App\Support\Money::format($document->total_minor) }}</td>
                                <td class="td">
                                    <x-ui.badge :tone="$document->isOverdue() ? 'danger' : $document->status->tone()">{{ $document->isOverdue() ? __('ui.documents.overdue') : $document->status->label() }}</x-ui.badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line p-4">{{ $documents->links() }}</div>
        @endif
    </x-ui.card>
</x-layouts.app>
