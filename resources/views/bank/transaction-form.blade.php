@php $current = old('type', $type->value); @endphp

<x-layouts.app :title="__('ui.bank.new_transaction')">
    <x-ui.page-header :title="__('ui.bank.new_transaction')">
        <x-slot:actions><x-ui.button :href="route('bank.index')" variant="secondary">{{ __('ui.common.back') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <form method="post" action="{{ route('bank.transactions.store') }}" class="max-w-3xl space-y-5" x-data="{ type: @js($current) }">
        @csrf
        <x-ui.card>
            <div class="mb-5 flex w-fit rounded-full bg-soft p-1 text-sm font-semibold" role="radiogroup" aria-label="{{ __('ui.bank.fields.type') }}">
                @foreach (\App\Enums\TransactionType::cases() as $case)
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="{{ $case->value }}" x-model="type" class="peer sr-only">
                        <span class="block rounded-full px-5 py-2 text-muted transition peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-4 peer-focus-visible:ring-brand-500/30">{{ $case->label() }}</span>
                    </label>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input name="amount" inputmode="decimal" :label="__('ui.bank.fields.amount').' ('.__('ui.currency.uzs').')'" placeholder="0" required />
                <x-ui.input name="occurred_on" type="date" :label="__('ui.bank.fields.date')" :value="now()->toDateString()" max="{{ now()->toDateString() }}" required />
                <x-ui.select name="account_id" :label="__('ui.bank.fields.account')" required>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>{{ $account->name }}</option>
                    @endforeach
                </x-ui.select>
                <div>
                    <label for="category_id" class="label">{{ __('ui.bank.fields.category') }}</label>
                    <select id="category_id" name="category_id" class="field">
                        <option value="">{{ __('ui.bank.no_category') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" x-show="type === '{{ $category->type->value }}'" :disabled="type !== '{{ $category->type->value }}'" @selected((int) old('category_id') === $category->id)>{{ $category->label() }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-ui.select class="sm:col-span-2" name="contractor_id" :label="__('ui.bank.fields.contractor')" :placeholder="__('ui.bank.no_contractor')">
                    @foreach ($contractors as $contractor)
                        <option value="{{ $contractor->id }}" @selected((int) old('contractor_id') === $contractor->id)>{{ $contractor->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input class="sm:col-span-2" name="description" :label="__('ui.bank.fields.description')" maxlength="255" />
                <x-ui.input name="reference" :label="__('ui.bank.fields.reference')" maxlength="60" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="route('bank.index')" variant="ghost">{{ __('ui.common.cancel') }}</x-ui.button>
            <x-ui.button type="submit">{{ __('ui.common.save') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
