@php
    $editing = $document->exists;
    $lineErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'lines'))->flatten()->unique();
    $formState = \Illuminate\Support\Js::from(['lines' => array_values($initialLines), 'currency' => __('ui.currency.uzs')])->toHtml();
@endphp

<x-layouts.app :title="$editing ? __('ui.documents.edit') : __('ui.documents.new')">
    <x-ui.page-header :title="$editing ? __('ui.documents.edit').' '.$document->number : __('ui.documents.new')">
        <x-slot:actions>
            <x-ui.button :href="$editing ? route('documents.show', $document) : route('documents.index')" variant="secondary">{{ __('ui.common.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form method="post" action="{{ $editing ? route('documents.update', $document) : route('documents.store') }}" class="space-y-5" x-data="documentForm({{ $formState }})">
        @csrf
        @if ($editing) @method('PUT') @endif

        @if ($lineErrors->isNotEmpty())
            <div class="rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200" role="alert">
                <ul class="list-disc space-y-0.5 pl-5">@foreach ($lineErrors as $message)<li>{{ $message }}</li>@endforeach</ul>
            </div>
        @endif

        <x-ui.card>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.select name="type" :label="__('ui.documents.fields.type')" :value="$document->type?->value" required>
                    @foreach (\App\Enums\DocumentType::cases() as $case)
                        <option value="{{ $case->value }}" @selected(old('type', $document->type?->value) === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="direction" :label="__('ui.documents.fields.direction')" :value="$document->direction" required>
                    <option value="out" @selected(old('direction', $document->direction) === 'out')>{{ __('ui.documents.directions.out') }}</option>
                    <option value="in" @selected(old('direction', $document->direction) === 'in')>{{ __('ui.documents.directions.in') }}</option>
                </x-ui.select>
                <x-ui.select class="sm:col-span-2" name="contractor_id" :label="__('ui.documents.fields.contractor')" :value="$document->contractor_id" :placeholder="__('ui.documents.choose_contractor')" required>
                    @foreach ($contractors as $contractor)
                        <option value="{{ $contractor->id }}" @selected((int) old('contractor_id', $document->contractor_id) === $contractor->id)>{{ $contractor->name }}{{ $contractor->stir ? ' · '.$contractor->stir : '' }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="issue_date" type="date" :label="__('ui.documents.fields.issue_date')" :value="$document->issue_date?->toDateString()" required />
                <x-ui.input name="due_date" type="date" :label="__('ui.documents.fields.due_date')" :value="$document->due_date?->toDateString()" />
                <x-ui.textarea class="sm:col-span-2" name="notes" rows="1" :label="__('ui.documents.fields.notes')" :value="$document->notes" />
            </div>
        </x-ui.card>

        <x-ui.card padding="p-0">
            <div class="flex items-center justify-between p-5 pb-3 sm:p-6 sm:pb-3">
                <h2 class="font-semibold">{{ __('ui.documents.fields.lines') }}</h2>
                <x-ui.button variant="soft" size="sm" icon="plus" @click="add()">{{ __('ui.documents.add_line') }}</x-ui.button>
            </div>

            <div class="overflow-x-auto px-5 pb-5 sm:px-6">
                <table class="w-full min-w-[52rem]">
                    <thead><tr>
                        <th class="th pl-0">{{ __('ui.documents.fields.line_name') }}</th>
                        <th class="th w-24">{{ __('ui.documents.fields.unit') }}</th>
                        <th class="th w-28">{{ __('ui.documents.fields.quantity') }}</th>
                        <th class="th w-40">{{ __('ui.documents.fields.unit_price') }}</th>
                        <th class="th w-24">{{ __('ui.documents.fields.vat_percent') }}</th>
                        <th class="th w-44 text-right">{{ __('ui.documents.fields.line_total') }}</th>
                        <th class="w-10"></th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(line, index) in lines" :key="index">
                            <tr class="align-top">
                                <td class="py-1.5 pr-2"><input class="field" type="text" :name="`lines[${index}][name]`" x-model="line.name" required maxlength="255" aria-label="{{ __('ui.documents.fields.line_name') }}"></td>
                                <td class="px-1 py-1.5"><input class="field" type="text" :name="`lines[${index}][unit]`" x-model="line.unit" maxlength="20" aria-label="{{ __('ui.documents.fields.unit') }}"></td>
                                <td class="px-1 py-1.5"><input class="field" type="text" inputmode="decimal" :name="`lines[${index}][quantity]`" x-model="line.quantity" required aria-label="{{ __('ui.documents.fields.quantity') }}"></td>
                                <td class="px-1 py-1.5"><input class="field" type="text" inputmode="decimal" :name="`lines[${index}][unit_price]`" x-model="line.unit_price" required placeholder="0" aria-label="{{ __('ui.documents.fields.unit_price') }}"></td>
                                <td class="px-1 py-1.5"><input class="field" type="text" inputmode="decimal" :name="`lines[${index}][vat_percent]`" x-model="line.vat_percent" aria-label="{{ __('ui.documents.fields.vat_percent') }}"></td>
                                <td class="amount px-3 py-3.5 text-right text-sm font-semibold" x-text="lineTotal(line)"></td>
                                <td class="py-1.5 pl-1"><button type="button" class="grid size-9 place-items-center rounded-full text-muted transition hover:bg-red-50 hover:text-red-600 disabled:opacity-30" @click="remove(index)" :disabled="lines.length === 1" aria-label="{{ __('ui.documents.remove_line') }}"><x-ui.icon name="trash" class="size-4" /></button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col items-end gap-1 border-t border-line bg-soft/60 px-5 py-4 text-sm sm:px-6">
                <p class="flex gap-6"><span class="text-muted">{{ __('ui.documents.fields.subtotal') }}</span><span class="amount w-44 text-right font-semibold" x-text="subtotal"></span></p>
                <p class="flex gap-6"><span class="text-muted">{{ __('ui.documents.fields.vat') }}</span><span class="amount w-44 text-right font-semibold" x-text="vat"></span></p>
                <p class="flex gap-6 text-base"><span class="font-semibold">{{ __('ui.documents.fields.total') }}</span><span class="amount w-44 text-right text-lg font-bold text-brand-700" x-text="total"></span></p>
                <p class="text-xs text-muted">{{ __('ui.documents.totals_hint') }}</p>
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="$editing ? route('documents.show', $document) : route('documents.index')" variant="ghost">{{ __('ui.common.cancel') }}</x-ui.button>
            <x-ui.button type="submit">{{ __('ui.common.save') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
