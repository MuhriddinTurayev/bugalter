<x-layouts.app :title="$employee->exists ? $employee->full_name : __('ui.employees.add')">
    <x-ui.page-header :title="$employee->exists ? $employee->full_name : __('ui.employees.add')" :subtitle="__('ui.employees.form_subtitle')" />

    <form method="post" action="{{ $employee->exists ? route('settings.employees.update', $employee) : route('settings.employees.store') }}" class="max-w-3xl space-y-5">
        @csrf
        @if ($employee->exists) @method('PUT') @endif

        <x-ui.card>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input class="sm:col-span-2" name="full_name" :label="__('ui.employees.fields.full_name')" :value="$employee->full_name" required />
                <x-ui.input name="pinfl" :label="__('ui.employees.fields.pinfl')" :value="$employee->pinfl" inputmode="numeric" maxlength="14" :hint="__('ui.employees.pinfl_hint')" />
                <x-ui.input name="phone" type="tel" :label="__('ui.employees.fields.phone')" :value="$employee->phone" />
                <x-ui.input name="position" :label="__('ui.employees.fields.position')" :value="$employee->position" />
                <x-ui.input name="department" :label="__('ui.employees.fields.department')" :value="$employee->department" />
                <x-ui.input name="hired_on" type="date" :label="__('ui.employees.fields.hired_on')" :value="$employee->hired_on?->toDateString()" />
                <x-ui.select name="status" :label="__('ui.employees.fields.status')" :value="$employee->status" required>
                    @foreach (['active', 'on_leave', 'dismissed'] as $status)
                        <option value="{{ $status }}" @selected($employee->status === $status)>{{ __('ui.employees.statuses.'.$status) }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input class="sm:col-span-2" name="salary" inputmode="decimal" :label="__('ui.employees.fields.salary')" :value="\App\Support\Money::toInputString((int) $employee->salary_minor)" :hint="__('ui.employees.salary_hint')" required />
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-ui.button :href="route('settings.employees')" variant="ghost">{{ __('ui.common.cancel') }}</x-ui.button>
            <x-ui.button type="submit">{{ __('ui.common.save') }}</x-ui.button>
        </div>
    </form>

    @if ($employee->exists)
        <form method="post" action="{{ route('settings.employees.destroy', $employee) }}" class="mt-6 max-w-3xl">
            @csrf @method('DELETE')
            <x-ui.confirm-button :message="__('ui.employees.confirm_delete')" icon="trash" class="!text-red-600">{{ __('ui.common.delete') }}</x-ui.confirm-button>
        </form>
    @endif
</x-layouts.app>
