@php use App\Enums\TransactionType; use App\Support\Money; @endphp

@if ($transactions->isEmpty())
    <x-ui.empty-state :title="__('ui.bank.empty')" :text="__('ui.bank.empty_text')" icon="receipt" />
@else
    <div class="overflow-x-auto">
        <table class="w-full min-w-[46rem]">
            <thead><tr class="border-b border-line">
                <th class="th">{{ __('ui.bank.fields.description') }}</th>
                <th class="th">{{ __('ui.bank.fields.category') }}</th>
                <th class="th">{{ __('ui.bank.fields.account') }}</th>
                <th class="th">{{ __('ui.bank.fields.date') }}</th>
                <th class="th">{{ __('ui.common.status') }}</th>
                <th class="th text-right">{{ __('ui.bank.fields.amount') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-line">
                @foreach ($transactions as $transaction)
                    <tr class="transition hover:bg-soft/60">
                        <td class="td">
                            <div class="flex items-center gap-3">
                                <span @class(['grid size-9 shrink-0 place-items-center rounded-full', 'bg-brand-50 text-brand-600' => $transaction->type === TransactionType::Income, 'bg-soft text-muted' => $transaction->type === TransactionType::Expense])>
                                    <x-ui.icon :name="$transaction->type === TransactionType::Income ? 'arrow-down' : 'arrow-up'" class="size-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate font-semibold">{{ $transaction->title() }}</p>
                                    @if ($transaction->contractor && $transaction->description)<p class="truncate text-xs text-muted">{{ $transaction->description }}</p>@endif
                                </div>
                            </div>
                        </td>
                        <td class="td text-muted">{{ $transaction->category?->label() ?? '—' }}</td>
                        <td class="td text-muted">{{ $transaction->account->name }}</td>
                        <td class="td tabular-nums">{{ $transaction->occurred_at->format('d.m.Y H:i') }}</td>
                        <td class="td">
                            <span class="inline-flex items-center gap-1.5 text-sm"><i @class(['size-1.5 rounded-full', 'bg-brand-500' => $transaction->status === 'completed', 'bg-amber-500' => $transaction->status !== 'completed'])></i>{{ __('ui.bank.statuses.'.$transaction->status) }}</span>
                        </td>
                        <td class="td amount text-right font-semibold {{ $transaction->type === TransactionType::Income ? 'text-brand-700' : '' }}">
                            {{ $transaction->type === TransactionType::Income ? '+' : '−' }}{{ Money::format($transaction->amount_minor) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="border-t border-line p-4">{{ $transactions->links() }}</div>
@endif
