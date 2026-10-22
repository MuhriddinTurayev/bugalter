@php
    use App\Enums\DocumentStatus;
    use App\Support\Money;

    $nextStatuses = collect($document->status->allowedTransitions())
        ->filter(fn ($next) => auth()->user()->can($next === DocumentStatus::Cancelled ? 'cancel' : 'changeStatus', $document));
@endphp

<x-layouts.app :title="$document->number">
    <x-ui.page-header :title="$document->number" :subtitle="$document->type->label().' · '.__('ui.documents.directions.'.$document->direction)">
        <x-slot:actions>
            <x-ui.badge :tone="$document->isOverdue() ? 'danger' : $document->status->tone()" class="!px-3.5 !py-1.5 !text-sm">{{ $document->isOverdue() ? __('ui.documents.overdue') : $document->status->label() }}</x-ui.badge>
            @can('update', $document)
                <x-ui.button :href="route('documents.edit', $document)" variant="secondary" icon="pencil">{{ __('ui.common.edit') }}</x-ui.button>
            @endcan
            <x-ui.button :href="route('documents.pdf', $document)" variant="dark" icon="download">{{ __('ui.documents.download_pdf') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card padding="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[34rem]">
                        <thead><tr class="border-b border-line">
                            <th class="th">#</th>
                            <th class="th">{{ __('ui.documents.fields.line_name') }}</th>
                            <th class="th text-right">{{ __('ui.documents.fields.quantity') }}</th>
                            <th class="th text-right">{{ __('ui.documents.fields.unit_price') }}</th>
                            <th class="th text-right">{{ __('ui.documents.fields.vat_percent') }}</th>
                            <th class="th text-right">{{ __('ui.documents.fields.line_total') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($document->lines as $line)
                                <tr>
                                    <td class="td text-muted">{{ $line->position }}</td>
                                    <td class="td font-medium">{{ $line->name }}</td>
                                    <td class="td amount text-right">{{ rtrim(rtrim($line->quantity, '0'), '.') }} {{ $line->unit }}</td>
                                    <td class="td amount text-right">{{ Money::format($line->unit_price_minor, false) }}</td>
                                    <td class="td amount text-right">{{ rtrim(rtrim(number_format($line->vat_rate_bp / 100, 2, ',', ''), '0'), ',') }}%</td>
                                    <td class="td amount text-right font-semibold">{{ Money::format($line->total_minor, false) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col items-end gap-1 border-t border-line bg-soft/60 px-6 py-4 text-sm">
                    <p class="flex gap-6"><span class="text-muted">{{ __('ui.documents.fields.subtotal') }}</span><span class="amount w-44 text-right font-semibold">{{ Money::format($document->subtotal_minor) }}</span></p>
                    <p class="flex gap-6"><span class="text-muted">{{ __('ui.documents.fields.vat') }}</span><span class="amount w-44 text-right font-semibold">{{ Money::format($document->vat_minor) }}</span></p>
                    <p class="flex gap-6"><span class="font-semibold">{{ __('ui.documents.fields.total') }}</span><span class="amount w-44 text-right text-lg font-bold text-brand-700">{{ Money::format($document->total_minor) }}</span></p>
                </div>
            </x-ui.card>

            @if ($document->notes)
                <x-ui.card>
                    <p class="label">{{ __('ui.documents.fields.notes') }}</p>
                    <p class="whitespace-pre-line text-sm">{{ $document->notes }}</p>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-5">
            <x-ui.card>
                <dl class="space-y-3 text-sm">
                    <div><dt class="label">{{ __('ui.documents.fields.contractor') }}</dt><dd class="font-semibold">{{ $document->contractor->name }}</dd><dd class="text-xs text-muted">{{ __('ui.contractors.fields.stir') }}: {{ $document->contractor->stir ?? '—' }}</dd></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><dt class="label">{{ __('ui.documents.fields.issue_date') }}</dt><dd class="tabular-nums">{{ $document->issue_date->format('d.m.Y') }}</dd></div>
                        <div><dt class="label">{{ __('ui.documents.fields.due_date') }}</dt><dd class="tabular-nums">{{ $document->due_date?->format('d.m.Y') ?? '—' }}</dd></div>
                    </div>
                    @if ($document->paid_at)
                        <div><dt class="label">{{ __('ui.documents.paid_at') }}</dt><dd class="tabular-nums">{{ $document->paid_at->format('d.m.Y H:i') }}</dd></div>
                    @endif
                    <div><dt class="label">{{ __('ui.documents.created_by') }}</dt><dd>{{ $document->creator?->name ?? '—' }}</dd></div>
                </dl>
            </x-ui.card>

            @if ($nextStatuses->isNotEmpty() || $errors->has('status'))
                <x-ui.card>
                    <h2 class="mb-3 font-semibold">{{ __('ui.documents.change_status') }}</h2>
                    @error('status')<p class="mb-3 text-sm font-medium text-red-600">{{ $message }}</p>@enderror

                    @foreach ($nextStatuses as $next)
                        <form method="post" action="{{ route('documents.status', $document) }}" class="mb-3 space-y-2 last:mb-0">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="{{ $next->value }}">
                            @if ($next === DocumentStatus::Paid)
                                <select name="account_id" class="field" aria-label="{{ __('ui.documents.pay_account') }}">
                                    <option value="">{{ __('ui.documents.no_transaction') }}</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                            @if ($next === DocumentStatus::Cancelled)
                                <x-ui.confirm-button :message="__('ui.documents.confirm_cancel')" variant="secondary" size="md" class="w-full !text-red-600">{{ __('ui.documents.actions.'.$next->value) }}</x-ui.confirm-button>
                            @else
                                <x-ui.button type="submit" :variant="$next === DocumentStatus::Paid ? 'primary' : 'soft'" class="w-full">{{ __('ui.documents.actions.'.$next->value) }}</x-ui.button>
                            @endif
                        </form>
                    @endforeach
                </x-ui.card>
            @endif

            @can('delete', $document)
                <form method="post" action="{{ route('documents.destroy', $document) }}">
                    @csrf @method('DELETE')
                    <x-ui.confirm-button :message="__('ui.documents.confirm_delete')" icon="trash" class="w-full !text-red-600">{{ __('ui.common.delete') }}</x-ui.confirm-button>
                </form>
            @endcan
        </div>
    </div>
</x-layouts.app>
