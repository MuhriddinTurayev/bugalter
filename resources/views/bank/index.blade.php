@php use App\Support\Money; @endphp

<x-layouts.app :title="__('ui.bank.title')">
    <x-ui.page-header :title="__('ui.bank.title')" :subtitle="__('ui.bank.subtitle')">
        <x-slot:actions>
            <x-ui.button :href="route('bank.categories.index')" variant="secondary">{{ __('ui.bank.categories') }}</x-ui.button>
            @can('import', \App\Models\Transaction::class)
                <x-ui.button :href="route('bank.import')" variant="secondary" icon="upload">{{ __('ui.bank.import') }}</x-ui.button>
            @endcan
            @can('create', \App\Models\Transaction::class)
                <x-ui.button :href="route('bank.transactions.create', ['type' => 'expense'])" variant="secondary" icon="arrow-up">{{ __('ui.bank.add_expense') }}</x-ui.button>
                <x-ui.button :href="route('bank.transactions.create', ['type' => 'income'])" icon="arrow-down">{{ __('ui.bank.add_income') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Accounts --}}
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($accounts as $account)
            <a href="{{ route('bank.index', ['account_id' => $account->id]) }}"
               @class([
                   'card block p-5 transition hover:-translate-y-0.5',
                   'ring-2 ring-brand-500' => $selectedAccount === $account->id,
                   'opacity-60' => ! $account->is_active,
               ])>
                <div class="flex items-center justify-between">
                    <span class="grid size-10 place-items-center rounded-full bg-brand-50 text-brand-600"><x-ui.icon :name="$account->type === \App\Enums\AccountType::Cash ? 'wallet' : 'bank'" class="size-5" /></span>
                    <x-ui.badge>{{ $account->type->label() }}</x-ui.badge>
                </div>
                <p class="mt-4 truncate text-sm font-semibold">{{ $account->name }}</p>
                <p class="amount text-xl font-semibold tracking-tight">{{ Money::format($account->balanceMinor()) }}</p>
                @if ($account->account_number)<p class="mt-0.5 truncate text-xs text-muted">{{ $account->bank_name }} · {{ $account->account_number }}</p>@endif
            </a>
        @endforeach
        @can('create', \App\Models\Account::class)
            <a href="{{ route('bank.accounts.create') }}" class="flex min-h-[9.5rem] flex-col items-center justify-center gap-2 rounded-[1.75rem] border-2 border-dashed border-line text-sm font-semibold text-muted transition hover:border-brand-400 hover:text-brand-700">
                <x-ui.icon name="plus" class="size-6" /> {{ __('ui.bank.new_account') }}
            </a>
        @endcan
    </div>

    {{-- Transactions --}}
    <x-ui.card padding="p-0">
        <form method="get" class="grid grid-cols-1 gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[1fr_9rem_11rem_11rem_9rem_9rem_auto]">
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
                    <option value="{{ $account->id }}" @selected($selectedAccount === $account->id)>{{ $account->name }}</option>
                @endforeach
            </select>
            <select name="category_id" class="field" aria-label="{{ __('ui.bank.fields.category') }}">
                <option value="">{{ __('ui.bank.all_categories') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) request('category_id') === $category->id)>{{ $category->label() }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="field" aria-label="{{ __('ui.common.from') }}">
            <input type="date" name="to" value="{{ request('to') }}" class="field" aria-label="{{ __('ui.common.to') }}">
            <div class="flex gap-2">
                <x-ui.button type="submit" variant="dark">{{ __('ui.common.filter') }}</x-ui.button>
                @if (request()->hasAny(['q', 'type', 'account_id', 'category_id', 'from', 'to']))
                    <x-ui.button :href="route('bank.index')" variant="ghost">{{ __('ui.common.reset') }}</x-ui.button>
                @endif
            </div>
        </form>
        @include('bank._transactions', ['transactions' => $transactions])
    </x-ui.card>
</x-layouts.app>
