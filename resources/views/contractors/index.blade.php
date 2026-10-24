<x-layouts.app :title="__('ui.contractors.title')">
    <x-ui.page-header :title="__('ui.contractors.title')" :subtitle="__('ui.contractors.subtitle')">
        <x-slot:actions>
            @can('export', \App\Models\Contractor::class)
                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                    <x-ui.button variant="secondary" icon="download" @click="open = !open">{{ __('ui.common.export') }}</x-ui.button>
                    <div x-cloak x-show="open" @click.outside="open = false" class="absolute right-0 z-20 mt-2 w-40 rounded-2xl bg-white p-1.5 shadow-2xl ring-1 ring-line">
                        <a class="block rounded-xl px-3 py-2 text-sm hover:bg-soft" href="{{ route('contractors.export') }}">Excel (.xlsx)</a>
                        <a class="block rounded-xl px-3 py-2 text-sm hover:bg-soft" href="{{ route('contractors.export', ['format' => 'csv']) }}">CSV</a>
                    </div>
                </div>
            @endcan
            @can('import', \App\Models\Contractor::class)
                <div x-data="{ open: false }">
                    <x-ui.button variant="secondary" icon="upload" @click="open = !open">{{ __('ui.common.import') }}</x-ui.button>
                    <div x-cloak x-show="open" x-transition class="fixed inset-0 z-40 grid place-items-center bg-ink/40 p-4" @keydown.escape.window="open = false">
                        <div class="card w-full max-w-md p-6" @click.outside="open = false">
                            <h2 class="text-lg font-semibold">{{ __('ui.contractors.import_title') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('ui.contractors.import_help') }}</p>
                            <form method="post" action="{{ route('contractors.import') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                                @csrf
                                <input type="file" name="file" accept=".xlsx,.csv,.txt" required class="field file:mr-3 file:rounded-full file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700">
                                @error('file')<p class="text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                                <div class="flex justify-end gap-2">
                                    <x-ui.button variant="ghost" @click="open = false">{{ __('ui.common.cancel') }}</x-ui.button>
                                    <x-ui.button type="submit">{{ __('ui.common.import') }}</x-ui.button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endcan
            @can('create', \App\Models\Contractor::class)
                <x-ui.button :href="route('contractors.create')" icon="plus">{{ __('ui.contractors.new') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card padding="p-0">
        <form method="get" class="grid grid-cols-1 gap-3 border-b border-line p-4 sm:grid-cols-[1fr_11rem_11rem_auto]">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ui.contractors.search') }}" class="field" aria-label="{{ __('ui.common.search') }}">
            <select name="type" class="field" aria-label="{{ __('ui.contractors.fields.type') }}">
                <option value="">{{ __('ui.contractors.all_types') }}</option>
                @foreach (\App\Enums\ContractorType::cases() as $case)
                    <option value="{{ $case->value }}" @selected($type === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
            <select name="status" class="field" aria-label="{{ __('ui.common.status') }}">
                <option value="active" @selected($status === 'active')>{{ __('ui.common.active') }}</option>
                <option value="inactive" @selected($status === 'inactive')>{{ __('ui.common.inactive') }}</option>
                <option value="all" @selected($status === 'all')>{{ __('ui.common.all') }}</option>
            </select>
            <div class="flex gap-2">
                <x-ui.button type="submit" variant="dark">{{ __('ui.common.filter') }}</x-ui.button>
                @if (request()->hasAny(['q', 'type', 'status']))
                    <x-ui.button :href="route('contractors.index')" variant="ghost">{{ __('ui.common.reset') }}</x-ui.button>
                @endif
            </div>
        </form>

        @if ($contractors->isEmpty())
            <x-ui.empty-state :title="__('ui.contractors.empty')" :text="__('ui.contractors.empty_text')" icon="users" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('ui.contractors.fields.name') }}</th>
                        <th class="th">{{ __('ui.contractors.fields.type') }}</th>
                        <th class="th">{{ __('ui.contractors.contacts') }}</th>
                        <th class="th">{{ __('ui.common.status') }}</th>
                        <th class="th text-right">{{ __('ui.common.actions') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($contractors as $contractor)
                            <tr class="transition hover:bg-soft/60">
                                <td class="td">
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :text="$contractor->initials()" />
                                        <div class="min-w-0">
                                            <a href="{{ route('contractors.edit', $contractor) }}" class="block truncate font-semibold hover:text-brand-700">{{ $contractor->name }}</a>
                                            <p class="text-xs text-muted">{{ __('ui.contractors.fields.stir') }}: {{ $contractor->stir ?? '—' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="td"><x-ui.badge>{{ $contractor->type->label() }}</x-ui.badge></td>
                                <td class="td text-muted">
                                    <p>{{ $contractor->phone ?? '—' }}</p>
                                    <p class="text-xs">{{ $contractor->email }}</p>
                                </td>
                                <td class="td">
                                    <x-ui.badge :tone="$contractor->is_active ? 'success' : 'neutral'">{{ $contractor->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</x-ui.badge>
                                </td>
                                <td class="td">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('update', $contractor)
                                            <x-ui.button :href="route('contractors.edit', $contractor)" variant="ghost" size="sm" icon="pencil">{{ __('ui.common.edit') }}</x-ui.button>
                                        @endcan
                                        @can('toggle', $contractor)
                                            <form method="post" action="{{ route('contractors.toggle', $contractor) }}">
                                                @csrf @method('PATCH')
                                                <x-ui.button type="submit" variant="ghost" size="sm" icon="power">{{ $contractor->is_active ? __('ui.contractors.deactivate') : __('ui.contractors.activate') }}</x-ui.button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line p-4">{{ $contractors->links() }}</div>
        @endif
    </x-ui.card>
</x-layouts.app>
