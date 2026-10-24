@php
    use App\Models\CabinetPayment;
    use App\Support\Money;

    $taxName = fn (string $code) => Lang::has('tax.payments.taxes.'.$code) ? __('tax.payments.taxes.'.$code) : __('tax.payments.tax_code', ['code' => $code]);
    $debts = collect($standing)->filter(fn ($b) => $b['debt_minor'] > 0 || $b['penalty_minor'] > 0 || $b['overpaid_minor'] > 0);
    $sorted = $mine->isNotEmpty();
@endphp

<x-layouts.app :title="__('tax.payments.title')">
    <x-ui.page-header :title="__('tax.payments.title')" :subtitle="__('tax.payments.subtitle')">
        <x-slot:actions>
            <x-ui.button :href="route('tax.index')" variant="secondary">{{ __('tax.back') }}</x-ui.button>
            <x-ui.button :href="route('tax.agent')" variant="secondary" icon="link">{{ __('tax.agent.title') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($mine->contains(fn ($p) => $p->isOpen()))
        <div x-data x-init="setTimeout(() => location.reload(), 5000)"></div>
    @endif

    <p class="mb-4 text-xs text-muted">
        @if ($check)
            {{ __('tax.payments.read_at', ['time' => $check->finished_at->format('d.m.Y H:i')]) }}
        @else
            {{ __('tax.payments.never_read') }}
        @endif
    </p>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            {{-- What is owed, per tax, as the cabinet shows it --}}
            <x-ui.card>
                <h2 class="font-semibold">{{ __('tax.payments.debts_title') }}</h2>
                @if (! $check)
                    <p class="mt-2 text-sm text-muted">{{ __('tax.payments.never_read') }}</p>
                @elseif ($debts->isEmpty())
                    <p class="mt-2 flex items-center gap-2 text-sm font-semibold text-brand-700"><x-ui.icon name="check" class="size-4" /> {{ __('tax.payments.no_debt') }}</p>
                @else
                    <ul class="mt-3 divide-y divide-line">
                        @foreach ($debts as $debt)
                            <li class="py-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-semibold">{{ $debt['name'] }}</p>
                                        @if ($debt['as_of'])<p class="text-xs text-muted">{{ __('tax.payments.as_of', ['date' => \Carbon\CarbonImmutable::parse($debt['as_of'])->format('d.m.Y')]) }}</p>@endif
                                    </div>
                                    @php $code = (string) ($debt['code'] ?? ''); @endphp
                                    @if ($canPay && array_key_exists($code, \App\Payments\PaymentCatalog::TAXES) && $debt['remaining_minor'] > 0)
                                        <x-ui.button size="sm" :href="route('tax.payments', ['tax' => $code, 'kind' => '08102', 'amount' => intdiv($debt['remaining_minor'] + 99, 100)]).'#new-payment'" icon="wallet">{{ __('tax.payments.close_debt') }}</x-ui.button>
                                    @endif
                                </div>
                                <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                                    <div><dt class="text-xs text-muted">{{ __('tax.payments.debt') }}</dt><dd class="font-semibold {{ $debt['debt_minor'] > 0 ? 'text-red-600' : '' }}">{{ Money::format($debt['debt_minor']) }}</dd></div>
                                    <div><dt class="text-xs text-muted">{{ __('tax.payments.penalty') }}</dt><dd class="font-semibold {{ $debt['penalty_minor'] > 0 ? 'text-red-600' : '' }}">{{ Money::format($debt['penalty_minor']) }}</dd></div>
                                    <div><dt class="text-xs text-muted">{{ __('tax.payments.overpaid') }}</dt><dd class="font-semibold text-brand-700">{{ Money::format($debt['overpaid_minor']) }}</dd></div>
                                    <div><dt class="text-xs text-muted">{{ __('tax.payments.paid_since') }}</dt><dd class="font-semibold">{{ Money::format($debt['paid_since_minor']) }}</dd></div>
                                </dl>
                                @if ($debt['debt_minor'] + $debt['penalty_minor'] > 0)
                                    <p class="mt-2 text-sm">{{ __('tax.payments.remaining') }}: <span class="font-bold {{ $debt['remaining_minor'] > 0 ? 'text-red-600' : 'text-brand-700' }}">{{ Money::format($debt['remaining_minor']) }}</span> <span class="text-xs text-muted">({{ __('tax.payments.estimate') }})</span></p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            {{-- The payment form: the robot makes the payment in the cabinet --}}
            @if ($canPay)
                <x-ui.card id="new-payment">
                    <h2 class="font-semibold">{{ __('tax.payments.new_title') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('tax.payments.new_hint') }}</p>

                    <form method="post" action="{{ route('tax.payments.store') }}" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2"
                          x-data="{ tax: @js(old('tax_code', $prefill['tax_code'])), kind: @js(old('kind', $prefill['kind'])), amount: @js(old('amount', $prefill['amount'])), taxes: @js(collect($taxCodes)->mapWithKeys(fn ($c) => [$c => $taxName($c)])), kinds: @js(collect($kinds)->mapWithKeys(fn ($k) => [$k => __('tax.payments.kinds.'.$k)])) }"
                          x-on:submit="if ($el.dataset.dry !== '1' && ! confirm(@js(__('tax.payments.confirm')).replace(':tax', tax + ' · ' + taxes[tax]).replace(':kind', kinds[kind]).replace(':amount', (amount || '0') + ' soʼm'))) $event.preventDefault()">
                        @csrf
                        <input type="hidden" name="client_token" value="{{ $token }}">

                        <x-ui.select name="tax_code" :label="__('tax.payments.tax')" x-model="tax" required>
                            @foreach ($taxCodes as $code)
                                <option value="{{ $code }}" @selected(old('tax_code', $prefill['tax_code']) === $code)>{{ $code }} · {{ $taxName($code) }}</option>
                            @endforeach
                        </x-ui.select>

                        <x-ui.select name="kind" :label="__('tax.payments.kind')" x-model="kind" required>
                            @foreach ($kinds as $kind)
                                <option value="{{ $kind }}" @selected(old('kind', $prefill['kind']) === $kind)>{{ $kind }} · {{ __('tax.payments.kinds.'.$kind) }}</option>
                            @endforeach
                        </x-ui.select>

                        <x-ui.input class="sm:col-span-2" name="amount" :label="__('tax.payments.amount_sum')" :value="$prefill['amount']" inputmode="decimal" x-model="amount" required :hint="__('tax.payments.amount_hint')" />
                        <x-ui.input class="sm:col-span-2" name="purpose" :label="__('tax.payments.purpose')" maxlength="200" :hint="__('tax.payments.purpose_hint')" />

                        <div class="sm:col-span-2">
                            <div class="flex flex-wrap gap-2">
                                <x-ui.button type="submit" icon="wallet" class="w-full sm:w-auto" x-on:click="$el.form.dataset.dry = ''">{{ __('tax.payments.pay') }}</x-ui.button>
                                <x-ui.button type="submit" name="dry_run" value="1" variant="secondary" icon="eye" class="w-full sm:w-auto" x-on:click="$el.form.dataset.dry = '1'">{{ __('tax.payments.dry') }}</x-ui.button>
                            </div>
                            <p class="mt-2 text-xs text-muted">{{ __('tax.payments.pay_note') }}</p>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- The payments started here --}}
            @if ($sorted)
                <x-ui.card padding="p-0">
                    <h2 class="px-5 pt-5 font-semibold sm:px-6">{{ __('tax.payments.mine_title') }}</h2>
                    <div class="mt-3 divide-y divide-line">
                        @foreach ($mine as $p)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6">
                                <div class="min-w-0">
                                    <p class="font-semibold">{{ $p->tax_code }} · {{ $taxName($p->tax_code) }}</p>
                                    <p class="text-xs text-muted">{{ $p->approved_at->format('d.m.Y H:i') }} · {{ $p->approved_by_name }} · {{ $p->kind }}@if ($p->order_number) · № {{ $p->order_number }}@endif</p>
                                    @if ($p->error)<p class="mt-1 text-xs text-red-600">{{ $p->error }}</p>@endif
                                    @if ($p->dry_run)<p class="mt-1 text-xs text-muted">{{ __('tax.payments.dry_note') }}</p>@endif
                                    @if ($p->status === 'unknown')<p class="mt-1 text-xs text-amber-700">{{ __('tax.payments.unknown_hint') }}</p>@endif
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="font-bold">{{ Money::format($p->amount_minor) }}</span>
                                    <x-ui.badge :tone="$p->tone()">{{ __('tax.payments.statuses.'.$p->status) }}</x-ui.badge>
                                    @if ($canPay && ($p->status === 'queued'))
                                        <form method="post" action="{{ route('tax.payments.cancel', $p) }}">@csrf
                                            <x-ui.confirm-button :message="__('tax.payments.confirm_cancel')" size="sm">{{ __('tax.cancel') }}</x-ui.confirm-button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            {{-- Every payment order the cabinet lists --}}
            <x-ui.card padding="p-0">
                <h2 class="px-5 pt-5 font-semibold sm:px-6">{{ __('tax.payments.cabinet_title') }}</h2>
                @if ($payments->isEmpty())
                    <x-ui.empty-state :title="__('tax.payments.empty')" icon="wallet" />
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[40rem]">
                            <thead><tr class="border-b border-line">
                                <th class="th">{{ __('tax.payments.date') }}</th>
                                <th class="th">{{ __('tax.payments.order') }}</th>
                                <th class="th">{{ __('tax.payments.tax') }}</th>
                                <th class="th text-right">{{ __('tax.payments.amount') }}</th>
                                <th class="th">{{ __('tax.payments.status') }}</th>
                            </tr></thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($payments as $payment)
                                    <tr>
                                        <td class="td whitespace-nowrap">{{ $payment->order_date->format('d.m.Y') }}</td>
                                        <td class="td">№ {{ $payment->order_number }}</td>
                                        <td class="td">
                                            <span class="font-semibold">{{ $payment->tax_code }}</span>
                                            <span class="block text-xs text-muted">{{ $taxName($payment->tax_code) }}</span>
                                        </td>
                                        <td class="td amount text-right font-semibold">{{ Money::format($payment->amount_minor) }}</td>
                                        <td class="td">
                                            @php $sent = ! $payment->is_paid && str_contains((string) $payment->status_text, 'Юборилди'); @endphp
                                            <x-ui.badge :tone="$payment->is_paid ? 'success' : ($sent ? 'info' : 'warning')">{{ $payment->is_paid ? __('tax.payments.paid') : ($sent ? __('tax.payments.sent_to_bank') : __('tax.payments.not_paid')) }}</x-ui.badge>
                                            @if ($sent)<span class="mt-1 block max-w-[16rem] text-xs text-muted">{{ __('tax.payments.sent_hint') }}</span>@endif
                                            <span class="mt-1 block text-xs text-muted">{{ CabinetPayment::mask($payment->payer_account) }} → {{ CabinetPayment::mask($payment->receiver_account) }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="px-5 py-3 sm:px-6">{{ $payments->links() }}</div>
                @endif
            </x-ui.card>
        </div>

        {{-- Paid this year, per tax --}}
        <div class="space-y-4">
            @forelse ($totals as $total)
                <x-ui.card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('tax.payments.paid_in', ['year' => $year]) }}</p>
                    <p class="mt-1 text-sm font-semibold">{{ $total->tax_code }} · {{ $taxName($total->tax_code) }}</p>
                    <p class="mt-2 text-2xl font-bold">{{ Money::format((int) $total->total_minor) }}</p>
                    <p class="text-xs text-muted">{{ __('tax.payments.orders', ['count' => $total->orders]) }}</p>
                </x-ui.card>
            @empty
                <x-ui.card><p class="text-sm text-muted">{{ __('tax.payments.empty') }}</p></x-ui.card>
            @endforelse
        </div>
    </div>
</x-layouts.app>
