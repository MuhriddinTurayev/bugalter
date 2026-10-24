@php
    use App\Enums\Permission;
    use App\Enums\PayrollStatus;
    use App\Support\Money;

    $canApprove = auth()->user()->hasPermission(Permission::PayrollApprove);
@endphp

<x-layouts.app :title="__('payroll.title').' · '.$run->periodLabel()">
    <x-ui.page-header :title="$run->periodLabel()" :subtitle="__('payroll.prepared_by', ['who' => $run->prepared_by_name ?? __('ui.actors.'.$run->prepared_actor)])">
        <x-slot:actions>
            <x-ui.badge :tone="$run->status->tone()" class="!px-3.5 !py-1.5 !text-sm">{{ $run->status->label() }}</x-ui.badge>
            <x-ui.button :href="route('payroll.index')" variant="secondary">{{ __('payroll.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <x-ui.card padding="p-0" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('payroll.employee') }}</th>
                        <th class="th text-right">{{ __('payroll.gross') }}</th>
                        <th class="th text-right">{{ __('payroll.pit') }}</th>
                        <th class="th text-right">{{ __('payroll.inps') }}</th>
                        <th class="th text-right">{{ __('payroll.social') }}</th>
                        <th class="th text-right">{{ __('payroll.net') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($run->lines as $line)
                            <tr>
                                <td class="td"><p class="font-semibold">{{ $line->employee_name }}</p><p class="text-xs text-muted">{{ $line->position }}</p></td>
                                <td class="td text-right">
                                    @if ($run->isEditable())
                                        <form method="post" action="{{ route('payroll.lines.update', [$run, $line]) }}" class="flex items-center justify-end gap-1">
                                            @csrf @method('PATCH')
                                            <input name="gross" value="{{ Money::toInputString($line->gross_minor) }}" inputmode="decimal" class="field w-36 !py-1.5 text-right tabular-nums" aria-label="{{ __('payroll.gross') }}">
                                            <button class="grid size-8 place-items-center rounded-full text-muted hover:bg-soft hover:text-ink" title="{{ __('ui.common.save') }}"><x-ui.icon name="check" class="size-4" /></button>
                                        </form>
                                    @else
                                        <span class="amount">{{ Money::format($line->gross_minor) }}</span>
                                    @endif
                                </td>
                                <td class="td amount text-right">{{ Money::format($line->pit_minor) }}</td>
                                <td class="td amount text-right text-muted">{{ Money::format($line->inps_minor) }}</td>
                                <td class="td amount text-right">{{ Money::format($line->social_minor) }}</td>
                                <td class="td amount text-right font-semibold">{{ Money::format($line->net_minor) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-ui.empty-state :title="__('payroll.no_lines')" icon="users" /></td></tr>
                        @endforelse
                    </tbody>
                    <tfoot><tr class="border-t border-line bg-soft/60 font-semibold">
                        <td class="td">{{ __('payroll.total') }}</td>
                        <td class="td amount text-right">{{ Money::format($run->gross_minor) }}</td>
                        <td class="td amount text-right">{{ Money::format($run->pit_minor) }}</td>
                        <td class="td amount text-right">{{ Money::format($run->inps_minor) }}</td>
                        <td class="td amount text-right">{{ Money::format($run->social_minor) }}</td>
                        <td class="td amount text-right text-brand-700">{{ Money::format($run->net_minor) }}</td>
                    </tr></tfoot>
                </table>
            </div>
        </x-ui.card>

        <div class="space-y-5">
            <x-ui.card>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">{{ __('payroll.pit_to_budget') }}</dt><dd class="amount font-semibold">{{ Money::format($run->pitToBudgetMinor()) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('payroll.inps_to_fund') }}</dt><dd class="amount font-semibold">{{ Money::format($run->inps_minor) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('payroll.social') }}</dt><dd class="amount font-semibold">{{ Money::format($run->social_minor) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-3"><dt class="font-semibold">{{ __('payroll.employer_cost') }}</dt><dd class="amount font-bold">{{ Money::format($run->gross_minor + $run->social_minor) }}</dd></div>
                </dl>
                @if (! empty($run->rules))
                    <p class="mt-4 text-xs text-muted">{{ __('payroll.rules_used') }}:
                        @foreach ($run->rules as $rule){{ $rule['code'] }} {{ rtrim(rtrim(number_format($rule['value'] / 100, 2, ',', ''), '0'), ',') }}%@if (! $rule['verified']) <span class="text-amber-700">({{ __('tax.unverified') }})</span>@endif{{ $loop->last ? '' : ', ' }}@endforeach
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-3 font-semibold">{{ __('tax.actions') }}</h2>
                <div class="space-y-2">
                    @if ($run->isEditable())
                        <form method="post" action="{{ route('payroll.store') }}">@csrf
                            <input type="hidden" name="month" value="{{ $run->period->format('Y-m') }}">
                            <x-ui.button type="submit" variant="secondary" class="w-full" icon="calculator">{{ __('payroll.refresh') }}</x-ui.button>
                        </form>
                        @if ($canApprove)
                            <form method="post" action="{{ route('payroll.approve', $run) }}">@csrf
                                <x-ui.confirm-button :message="__('payroll.confirm_approve')" variant="primary" size="md" class="w-full">{{ __('payroll.approve') }}</x-ui.confirm-button>
                            </form>
                        @endif
                    @endif

                    @if ($run->status === PayrollStatus::Approved && $canApprove)
                        <form method="post" action="{{ route('payroll.pay', $run) }}" class="space-y-2">@csrf
                            <x-ui.select name="account_id" :label="__('payroll.pay_from')" required>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.confirm-button :message="__('payroll.confirm_pay', ['amount' => Money::format($run->net_minor)])" variant="primary" size="md" class="w-full">{{ __('payroll.mark_paid') }}</x-ui.confirm-button>
                        </form>
                        <form method="post" action="{{ route('payroll.reopen', $run) }}">@csrf
                            <x-ui.button type="submit" variant="ghost" class="w-full">{{ __('payroll.reopen') }}</x-ui.button>
                        </form>
                    @endif

                    @if ($run->status === PayrollStatus::Paid)
                        <p class="text-sm text-muted">{{ __('payroll.paid_note', ['date' => $run->paid_at?->format('d.m.Y')]) }}</p>
                    @endif
                    @if ($run->approver)
                        <p class="text-xs text-muted">{{ __('payroll.approved_by', ['name' => $run->approver->name, 'date' => $run->approved_at?->format('d.m.Y H:i')]) }}</p>
                    @endif
                </div>
            </x-ui.card>

            @if ($run->status !== PayrollStatus::Draft)
                <x-ui.button :href="route('tax.index')" variant="soft" class="w-full" icon="calculator">{{ __('payroll.to_tax_report') }}</x-ui.button>
            @endif
        </div>
    </div>
</x-layouts.app>
