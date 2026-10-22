@php $editing = $contractor->exists; @endphp

<x-layouts.app :title="$editing ? __('ui.contractors.edit') : __('ui.contractors.new')">
    <x-ui.page-header :title="$editing ? __('ui.contractors.edit') : __('ui.contractors.new')">
        <x-slot:actions>
            <x-ui.button :href="route('contractors.index')" variant="secondary">{{ __('ui.common.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form method="post" action="{{ $editing ? route('contractors.update', $contractor) : route('contractors.store') }}" class="max-w-4xl space-y-5">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-ui.card>
            <h2 class="mb-4 font-semibold">{{ __('ui.contractors.main') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input class="sm:col-span-2" name="name" :label="__('ui.contractors.fields.name')" :value="$contractor->name" required />
                <x-ui.input name="stir" :label="__('ui.contractors.fields.stir')" :value="$contractor->stir" inputmode="numeric" maxlength="14" :hint="__('ui.contractors.hints.stir')" />
                <x-ui.select name="type" :label="__('ui.contractors.fields.type')" :value="$contractor->type?->value" required>
                    @foreach (\App\Enums\ContractorType::cases() as $case)
                        <option value="{{ $case->value }}" @selected(old('type', $contractor->type?->value) === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="phone" type="tel" :label="__('ui.contractors.fields.phone')" :value="$contractor->phone" />
                <x-ui.input name="email" type="email" :label="__('ui.contractors.fields.email')" :value="$contractor->email" />
                <x-ui.input class="sm:col-span-2" name="address" :label="__('ui.contractors.fields.address')" :value="$contractor->address" />
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-4 font-semibold">{{ __('ui.contractors.bank') }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input name="bank_name" :label="__('ui.contractors.fields.bank_name')" :value="$contractor->bank_name" />
                <x-ui.input name="bank_account" :label="__('ui.contractors.fields.bank_account')" :value="$contractor->bank_account" inputmode="numeric" maxlength="20" />
                <x-ui.input name="mfo" :label="__('ui.contractors.fields.mfo')" :value="$contractor->mfo" inputmode="numeric" maxlength="5" />
                <x-ui.textarea class="sm:col-span-3" name="notes" :label="__('ui.contractors.fields.notes')" :value="$contractor->notes" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="route('contractors.index')" variant="ghost">{{ __('ui.common.cancel') }}</x-ui.button>
            <x-ui.button type="submit">{{ __('ui.common.save') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
