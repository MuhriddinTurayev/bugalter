@php use App\Support\Money; @endphp

<x-layouts.app :title="__('payroll.title')">
    <x-ui.page-header :title="__('payroll.title')" :subtitle="__('payroll.subtitle')">
        <x-slot:actions>
            <x-ui.button :href="route('settings.employees')" variant="secondary" icon="users">{{ __('payroll.employees') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <x-ui.card padding="p-0" class="xl:col-span-2">
            @if ($runs->isEmpty())
                <x-ui.empty-state :title="__('payroll.empty')" :text="__('payroll.empty_text')" icon="briefcase" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem]">
                        <thead><tr class="border-b border-line">
                            <th class="th">{{ __('payroll.month') }}</th>
                            <th class="th">{{ __('ui.common.status') }}</th>
                            <th class="th text-right">{{ __('payroll.gross') }}</th>
                            <th class="th text-right">{{ __('payroll.pit') }}</th>
                            <th class="th text-right">{{ __('payroll.social') }}</th>
                            <th class="th text-right">{{ __('payroll.net') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($runs as $run)
                                <tr class="cursor-pointer transition hover:bg-soft/70" onclick="window.location='{{ route('payroll.show', $run) }}'">
                                    <td class="td font-semibold"><a href="{{ route('payroll.show', $run) }}">{{ $run->periodLabel() }}</a>
                                        <p class="text-xs font-normal text-muted">{{ $run->prepared_by_name ?? __('ui.actors.'.$run->prepared_actor) }}</p></td>
                                    <td class="td"><x-ui.badge :tone="$run->status->tone()">{{ $run->status->label() }}</x-ui.badge></td>
                                    <td class="td amount text-right">{{ Money::format($run->gross_minor) }}</td>
                                    <td class="td amount text-right">{{ Money::format($run->pit_minor) }}</td>
                                    <td class="td amount text-right">{{ Money::format($run->social_minor) }}</td>
                                    <td class="td amount text-right font-semibold">{{ Money::format($run->net_minor) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-line p-4">{{ $runs->links() }}</div>
            @endif
        </x-ui.card>

        <div class="space-y-5">
            <x-ui.card>
                <h2 class="mb-1 font-semibold">{{ __('payroll.prepare') }}</h2>
                <p class="mb-4 text-xs text-muted">{{ __('payroll.prepare_hint') }}</p>
                <form method="post" action="{{ route('payroll.store') }}" class="space-y-3">
                    @csrf
                    <x-ui.input name="month" type="month" :label="__('payroll.month')" :value="$suggestedMonth" required />
                    <x-ui.button type="submit" class="w-full" icon="calculator">{{ __('payroll.calculate') }}</x-ui.button>
                </form>
            </x-ui.card>
            <p class="text-xs text-muted">{{ __('payroll.note') }}</p>
        </div>
    </div>
</x-layouts.app>
