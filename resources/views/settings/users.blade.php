<x-layouts.app :title="__('ui.settings.users')">
    @include('settings._nav')

    <x-ui.card padding="p-0">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[36rem]">
                <thead><tr class="border-b border-line">
                    <th class="th">{{ __('ui.settings.user') }}</th>
                    <th class="th">{{ __('ui.settings.role') }}</th>
                    <th class="th">{{ __('ui.contractors.fields.phone') }}</th>
                    <th class="th">{{ __('ui.common.status') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($users as $user)
                        <tr>
                            <td class="td"><div class="flex items-center gap-3"><x-ui.avatar :text="$user->initials()" tone="dark" /><div><p class="font-semibold">{{ $user->name }}</p><p class="text-xs text-muted">{{ $user->email }}</p></div></div></td>
                            <td class="td"><x-ui.badge :tone="$user->role === \App\Enums\Role::Owner ? 'success' : 'neutral'">{{ $user->role->label() }}</x-ui.badge></td>
                            <td class="td text-muted tabular-nums">{{ $user->phone ?? '—' }}</td>
                            <td class="td"><x-ui.badge :tone="$user->is_active ? 'success' : 'neutral'">{{ $user->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
    <p class="mt-4 text-xs text-muted">{{ __('ui.settings.users_note') }}</p>
</x-layouts.app>
