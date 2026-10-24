@php
    use App\Enums\ProposalStatus;
    use App\Support\Money;

    $tabs = collect(ProposalStatus::cases())->map(fn ($s) => [
        'label' => $s->label(),
        'href' => route('ai.proposals', ['status' => $s->value]),
        'active' => $status === $s,
    ])->all();
@endphp

<x-layouts.app :title="__('ai.proposals')">
    <x-ui.page-header :title="__('ai.proposals')" :subtitle="__('ai.proposals_subtitle')">
        <x-slot:actions>
            <form method="post" action="{{ route('ai.classify') }}">@csrf
                <x-ui.button type="submit" variant="secondary" icon="sparkles">{{ __('ai.classify_now') }}</x-ui.button>
            </form>
            @if ($status === ProposalStatus::Pending && $proposals->isNotEmpty())
                <form method="post" action="{{ route('ai.apply-all') }}" class="flex items-center gap-2">@csrf
                    <select name="min_confidence" class="field !w-auto !py-2" aria-label="{{ __('ai.min_confidence') }}">
                        @foreach ([95, 90, 80, 70] as $value)
                            <option value="{{ $value }}">≥ {{ $value }}%</option>
                        @endforeach
                    </select>
                    <x-ui.confirm-button :message="__('ai.confirm_apply_all')" variant="primary" size="md" icon="check">{{ __('ai.apply_all') }}</x-ui.confirm-button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.subnav :items="$tabs" />

    <x-ui.card padding="p-0">
        @if ($proposals->isEmpty())
            <x-ui.empty-state :title="__('ai.proposals_empty')" :text="__('ai.proposals_empty_text')" icon="check" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('ai.transaction') }}</th>
                        <th class="th text-right">{{ __('ui.bank.fields.amount') }}</th>
                        <th class="th">{{ __('ai.suggested_category') }}</th>
                        <th class="th">{{ __('ai.confidence') }}</th>
                        <th class="th">{{ __('ai.reason') }}</th>
                        <th class="th"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($proposals as $proposal)
                            @php $t = $transactions[$proposal->subject_id] ?? null; @endphp
                            <tr>
                                <td class="td">
                                    @if ($t)
                                        <p class="font-semibold">{{ $t->title() }}</p>
                                        <p class="text-xs text-muted">{{ $t->occurred_at->format('d.m.Y') }} · {{ $t->account->name }}@if ($t->category) · {{ __('ai.now') }}: {{ $t->category->label() }}@endif</p>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="td amount text-right {{ $t?->type->value === 'income' ? 'text-brand-700' : '' }}">{{ $t ? ($t->type->value === 'income' ? '+' : '−').Money::format($t->amount_minor) : '—' }}</td>
                                <td class="td font-semibold">{{ $proposal->payload['category'] ?? '—' }}</td>
                                <td class="td">
                                    <x-ui.badge :tone="$proposal->confidence >= 90 ? 'success' : ($proposal->confidence >= 70 ? 'info' : 'warning')">{{ $proposal->confidence }}%</x-ui.badge>
                                    <p class="mt-1 text-xs text-muted">{{ __('ai.sources.'.$proposal->source) }}</p>
                                </td>
                                <td class="td max-w-xs text-xs text-muted">{{ $proposal->reason }}
                                    @if ($proposal->decided_by_name)<p class="mt-1">{{ __('ai.decided_by', ['name' => $proposal->decided_by_name]) }}</p>@endif</td>
                                <td class="td text-right">
                                    @if ($proposal->status === ProposalStatus::Pending)
                                        <div class="flex justify-end gap-1">
                                            <form method="post" action="{{ route('ai.decide', $proposal) }}">@csrf @method('PATCH')
                                                <input type="hidden" name="decision" value="apply">
                                                <x-ui.button type="submit" size="sm" icon="check">{{ __('ai.apply') }}</x-ui.button>
                                            </form>
                                            <form method="post" action="{{ route('ai.decide', $proposal) }}">@csrf @method('PATCH')
                                                <input type="hidden" name="decision" value="reject">
                                                <x-ui.button type="submit" size="sm" variant="ghost" icon="x">{{ __('ai.reject') }}</x-ui.button>
                                            </form>
                                        </div>
                                    @else
                                        <x-ui.badge :tone="$proposal->status->tone()">{{ $proposal->status->label() }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line p-4">{{ $proposals->links() }}</div>
        @endif
    </x-ui.card>
</x-layouts.app>
