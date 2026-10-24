<x-layouts.app :title="__('ui.settings.employees')">
    @include('settings._nav')

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted">{{ __('ui.employees.monthly_total', ['amount' => \App\Support\Money::format($payrollTotal)]) }}</p>
        <div class="flex gap-2">
            <x-ui.button :href="route('payroll.index')" variant="secondary" icon="briefcase">{{ __('ui.nav.payroll') }}</x-ui.button>
            <x-ui.button :href="route('settings.employees.create')" icon="plus">{{ __('ui.employees.add') }}</x-ui.button>
        </div>
    </div>

    <x-ui.card padding="p-0">
        @if ($employees->isEmpty())
            <x-ui.empty-state :title="__('ui.settings.employees_empty')" icon="users">
                <x-ui.button :href="route('settings.employees.create')" icon="plus">{{ __('ui.employees.add') }}</x-ui.button>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('ui.settings.employee') }}</th>
                        <th class="th">{{ __('ui.settings.position') }}</th>
                        <th class="th">{{ __('ui.settings.pinfl') }}</th>
                        <th class="th">{{ __('ui.settings.hired_on') }}</th>
                        <th class="th">{{ __('ui.common.status') }}</th>
                        <th class="th text-right">{{ __('ui.settings.salary') }}</th>
                        <th class="th"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($employees as $employee)
                            <tr>
                                <td class="td"><p class="font-semibold">{{ $employee->full_name }}</p><p class="text-xs text-muted">{{ $employee->phone }}</p></td>
                                <td class="td">{{ $employee->position }}<p class="text-xs text-muted">{{ $employee->department }}</p></td>
                                <td class="td font-mono text-xs {{ $employee->pinfl ? 'text-muted' : 'text-red-600' }}">{{ $employee->pinfl ? $employee->maskedPinfl() : __('ui.employees.no_pinfl') }}</td>
                                <td class="td tabular-nums">{{ $employee->hired_on?->format('d.m.Y') ?? '—' }}</td>
                                <td class="td"><x-ui.badge :tone="$employee->status === 'active' ? 'success' : 'neutral'">{{ __('ui.employees.statuses.'.$employee->status) }}</x-ui.badge></td>
                                <td class="td amount text-right font-semibold">{{ \App\Support\Money::format($employee->salary_minor) }}</td>
                                <td class="td text-right"><x-ui.button size="sm" variant="ghost" icon="pencil" :href="route('settings.employees.edit', $employee)">{{ __('ui.common.edit') }}</x-ui.button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
    <p class="mt-4 text-xs text-muted">{{ __('ui.employees.note') }}</p>
</x-layouts.app>
