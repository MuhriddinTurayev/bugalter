<x-layouts.app :title="__('ui.settings.employees')">
    @include('settings._nav')

    <x-ui.card padding="p-0">
        @if ($employees->isEmpty())
            <x-ui.empty-state :title="__('ui.settings.employees_empty')" icon="users" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('ui.settings.employee') }}</th>
                        <th class="th">{{ __('ui.settings.position') }}</th>
                        <th class="th">{{ __('ui.settings.pinfl') }}</th>
                        <th class="th">{{ __('ui.settings.hired_on') }}</th>
                        <th class="th text-right">{{ __('ui.settings.salary') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($employees as $employee)
                            <tr>
                                <td class="td"><p class="font-semibold">{{ $employee->full_name }}</p><p class="text-xs text-muted">{{ $employee->phone }}</p></td>
                                <td class="td">{{ $employee->position }}<p class="text-xs text-muted">{{ $employee->department }}</p></td>
                                <td class="td font-mono text-xs text-muted">{{ $employee->maskedPinfl() }}</td>
                                <td class="td tabular-nums">{{ $employee->hired_on?->format('d.m.Y') ?? '—' }}</td>
                                <td class="td amount text-right font-semibold">{{ \App\Support\Money::format($employee->salary_minor) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
    <p class="mt-4 text-xs text-muted">{{ __('ui.settings.employees_note') }}</p>
</x-layouts.app>
