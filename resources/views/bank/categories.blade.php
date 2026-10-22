@php use App\Enums\TransactionType; @endphp

<x-layouts.app :title="__('ui.bank.categories')">
    <x-ui.page-header :title="__('ui.bank.categories')" :subtitle="__('ui.bank.categories_subtitle')">
        <x-slot:actions><x-ui.button :href="route('bank.index')" variant="secondary">{{ __('ui.common.back') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="grid gap-5 sm:grid-cols-2 lg:col-span-2">
            @foreach (TransactionType::cases() as $type)
                <x-ui.card padding="p-0">
                    <h2 class="border-b border-line px-5 py-4 font-semibold">{{ $type->label() }}</h2>
                    <ul class="divide-y divide-line">
                        @forelse ($categories->where('type', $type) as $category)
                            <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                <div class="min-w-0">
                                    <p @class(['truncate font-medium', 'text-muted line-through' => ! $category->is_active])>{{ $category->label() }}</p>
                                    <p class="text-xs text-muted">{{ trans_choice('ui.bank.transactions_count', $category->transactions_count, ['count' => $category->transactions_count]) }}</p>
                                </div>
                                @can('update', $category)
                                    <form method="post" action="{{ route('bank.categories.toggle', $category) }}">@csrf @method('PATCH')
                                        <x-ui.button type="submit" variant="ghost" size="sm">{{ $category->is_active ? __('ui.contractors.deactivate') : __('ui.contractors.activate') }}</x-ui.button>
                                    </form>
                                @endcan
                            </li>
                        @empty
                            <li class="px-5 py-6 text-center text-sm text-muted">{{ __('ui.bank.no_categories') }}</li>
                        @endforelse
                    </ul>
                </x-ui.card>
            @endforeach
        </div>

        @can('create', \App\Models\Category::class)
            <x-ui.card class="self-start">
                <h2 class="mb-4 font-semibold">{{ __('ui.bank.new_category') }}</h2>
                <form method="post" action="{{ route('bank.categories.store') }}" class="space-y-4">
                    @csrf
                    <x-ui.input name="name" :label="__('ui.bank.fields.name')" maxlength="100" required />
                    <x-ui.select name="type" :label="__('ui.bank.fields.type')" required>
                        @foreach (TransactionType::cases() as $case)
                            <option value="{{ $case->value }}" @selected(old('type') === $case->value)>{{ $case->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button type="submit" class="w-full" icon="plus">{{ __('ui.common.create') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endcan
    </div>
</x-layouts.app>
