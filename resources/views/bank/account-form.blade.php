@php $editing = $account->exists; @endphp

<x-layouts.app :title="$editing ? __('ui.bank.edit_account') : __('ui.bank.new_account')">
    <x-ui.page-header :title="$editing ? __('ui.bank.edit_account') : __('ui.bank.new_account')">
        <x-slot:actions><x-ui.button :href="route('bank.index')" variant="secondary">{{ __('ui.common.back') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <form method="post" action="{{ $editing ? route('bank.accounts.update', $account) : route('bank.accounts.store') }}" class="max-w-3xl space-y-5" x-data="{ type: @js(old('type', $account->type?->value ?? 'bank')) }">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-ui.card>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input name="name" :label="__('ui.bank.fields.name')" :value="$account->name" required />
                <div>
                    <label for="type" class="label">{{ __('ui.bank.fields.type') }}</label>
                    <select id="type" name="type" class="field" x-model="type">
                        @foreach (\App\Enums\AccountType::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <template x-if="type === 'bank'">
                    <div class="grid grid-cols-1 gap-4 sm:col-span-2 sm:grid-cols-2">
                        <x-ui.input name="bank_name" :label="__('ui.bank.fields.bank_name')" :value="$account->bank_name" />
                        <x-ui.input name="account_number" :label="__('ui.bank.fields.account_number')" :value="$account->account_number" inputmode="numeric" maxlength="20" />
                    </div>
                </template>
                <x-ui.input name="opening_balance" inputmode="decimal" :label="__('ui.bank.fields.opening_balance').' ('.__('ui.currency.uzs').')'" :value="\App\Support\Money::toInputString($account->opening_balance_minor ?? 0)" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="route('bank.index')" variant="ghost">{{ __('ui.common.cancel') }}</x-ui.button>
            <x-ui.button type="submit">{{ __('ui.common.save') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
