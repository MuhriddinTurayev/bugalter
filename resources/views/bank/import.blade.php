@php
    $columnCount = $preview ? count($preview['headers']) : 0;
@endphp

<x-layouts.app :title="__('ui.bank.import')">
    <x-ui.page-header :title="__('ui.bank.import')" :subtitle="__('ui.bank.import_subtitle')">
        <x-slot:actions><x-ui.button :href="route('bank.index')" variant="secondary">{{ __('ui.common.back') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    @if (! $preview)
        <x-ui.card class="max-w-2xl">
            <h2 class="font-semibold">{{ __('ui.bank.import_step1') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('ui.bank.import_help') }}</p>
            <form method="post" action="{{ route('bank.import.upload') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf
                <input type="file" name="file" accept=".csv,.txt" required class="field file:mr-3 file:rounded-full file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700">
                @error('file')<p class="text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                <x-ui.button type="submit" icon="upload">{{ __('ui.bank.import_upload') }}</x-ui.button>
            </form>
        </x-ui.card>
    @else
        <form method="post" action="{{ route('bank.import.store') }}" class="space-y-5">
            @csrf
            <x-ui.card padding="p-0">
                <h2 class="border-b border-line px-5 py-4 font-semibold">{{ __('ui.bank.import_preview') }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[32rem]">
                        <thead><tr class="border-b border-line">@foreach ($preview['headers'] as $i => $header)<th class="th">{{ $header ?: '#'.($i + 1) }}</th>@endforeach</tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($preview['rows'] as $row)
                                <tr>@for ($i = 0; $i < $columnCount; $i++)<td class="td max-w-[14rem] truncate text-muted">{{ $row[$i] ?? '' }}</td>@endfor</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-1 font-semibold">{{ __('ui.bank.import_step2') }}</h2>
                <p class="mb-4 text-sm text-muted">{{ __('ui.bank.import_map_help') }}</p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([['date', true], ['amount', true], ['description', false], ['type', false], ['reference', false]] as [$field, $required])
                        <div>
                            <label for="col_{{ $field }}" class="label">{{ __('ui.bank.import_columns.'.$field) }}@if ($required)<span class="text-red-500"> *</span>@endif</label>
                            <select id="col_{{ $field }}" name="col_{{ $field }}" class="field {{ $errors->has('col_'.$field) ? 'field-invalid' : '' }}" @required($required)>
                                <option value="">{{ $required ? __('ui.bank.import_choose') : __('ui.bank.import_skip') }}</option>
                                @foreach ($preview['headers'] as $i => $header)
                                    <option value="{{ $i }}" @selected((string) old('col_'.$field, $defaultColumns[$field] ?? '') === (string) $i)>{{ $header ?: '#'.($i + 1) }}</option>
                                @endforeach
                            </select>
                            @error('col_'.$field)<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <x-ui.select name="date_format" :label="__('ui.bank.import_date_format')" required>
                        @foreach ($dateFormats as $format)
                            <option value="{{ $format }}" @selected(old('date_format') === $format)>{{ now()->format($format) }} ({{ $format }})</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select name="account_id" :label="__('ui.bank.fields.account')" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <p class="mt-4 text-xs text-muted">{{ __('ui.bank.import_sign_hint') }}</p>
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <x-ui.button type="submit" form="cancel-import" variant="ghost">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ __('ui.bank.import_confirm') }}</x-ui.button>
            </div>
        </form>
        <form id="cancel-import" method="post" action="{{ route('bank.import.cancel') }}">@csrf</form>
    @endif
</x-layouts.app>
