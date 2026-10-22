<x-layouts.app :title="__('ui.history.title')">
    <x-ui.page-header :title="__('ui.history.title')" :subtitle="__('ui.history.subtitle')" />

    <x-ui.card padding="p-0">
        <form method="get" class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[1fr_9rem_11rem_9rem_9rem_auto]">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ui.bank.search') }}" class="field" aria-label="{{ __('ui.common.search') }}">
            <select name="type" class="field" aria-label="{{ __('ui.bank.fields.type') }}">
                <option value="">{{ __('ui.common.all') }}</option>
                @foreach (\App\Enums\TransactionType::cases() as $case)
                    <option value="{{ $case->value }}" @selected(request('type') === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>
            <select name="account_id" class="field" aria-label="{{ __('ui.bank.fields.account') }}">
                <option value="">{{ __('ui.bank.all_accounts') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected((int) request('account_id') === $account->id)>{{ $account->name }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="field" aria-label="{{ __('ui.common.from') }}">
            <input type="date" name="to" value="{{ request('to') }}" class="field" aria-label="{{ __('ui.common.to') }}">
            <div class="flex gap-2">
                <x-ui.button type="submit" variant="dark">{{ __('ui.common.filter') }}</x-ui.button>
                @if (request()->hasAny(['q', 'type', 'account_id', 'from', 'to']))
                    <x-ui.button :href="route('history')" variant="ghost">{{ __('ui.common.reset') }}</x-ui.button>
                @endif
            </div>
        </form>
        @include('bank._transactions', ['transactions' => $transactions])
    </x-ui.card>
</x-layouts.app>
