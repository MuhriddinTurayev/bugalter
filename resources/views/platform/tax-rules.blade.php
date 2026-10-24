@php
    $staff = auth('platform')->user();
    $unverified = $rules->where('status', '!=', 'verified')->count() + $deadlines->where('status', '!=', 'verified')->count();
@endphp

<x-layouts.platform :title="__('platform.tax_rules.title')">
    <x-ui.page-header :title="__('platform.tax_rules.title')" :subtitle="__('platform.tax_rules.subtitle')">
        @if ($staff->isSuperAdmin())
            <x-slot:actions>
                @if ($unverified > 0)
                    <form method="post" action="{{ platform_route('tax-rules.verify-all') }}">@csrf
                        <x-ui.confirm-button :message="__('platform.tax_rules.confirm_verify_all', ['count' => $unverified])" variant="primary" size="md" icon="check">{{ __('platform.tax_rules.verify_all') }}</x-ui.confirm-button>
                    </form>
                @endif
                <form method="post" action="{{ platform_route('tax-rules.sync') }}">@csrf
                    <x-ui.confirm-button :message="__('platform.tax_rules.confirm_sync')" variant="secondary" size="md" icon="download">{{ __('platform.tax_rules.sync') }}</x-ui.confirm-button>
                </form>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($unverified > 0)
        <div class="mb-5 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">{{ __('platform.tax_rules.unverified_banner', ['count' => $unverified]) }}</div>
    @endif

    <x-ui.card padding="p-0">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[60rem]">
                <thead><tr class="border-b border-line">
                    <th class="th">{{ __('platform.tax_rules.rule') }}</th>
                    <th class="th">{{ __('platform.tax_rules.value') }}</th>
                    <th class="th">{{ __('platform.tax_rules.effective') }}</th>
                    <th class="th">{{ __('platform.tax_rules.source') }}</th>
                    <th class="th">{{ __('platform.tax_rules.status') }}</th>
                    <th class="th"></th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rules as $rule)
                        <tr x-data="{ edit: false }">
                            <td class="td"><p class="font-semibold">{{ $rule->label() }}</p><p class="font-mono text-xs text-muted">{{ $rule->code }} · v{{ $rule->version }}</p></td>
                            <td class="td amount font-semibold">{{ $rule->valueLabel() }}</td>
                            <td class="td tabular-nums text-xs">{{ $rule->effective_from->format('d.m.Y') }} — {{ $rule->effective_to?->format('d.m.Y') ?? '…' }}</td>
                            <td class="td max-w-xs text-xs text-muted">{{ $rule->source }}</td>
                            <td class="td">
                                <x-ui.badge :tone="$rule->isVerified() ? 'success' : 'warning'">{{ $rule->isVerified() ? __('platform.tax_rules.verified_badge') : __('platform.tax_rules.unverified_badge') }}</x-ui.badge>
                                @if ($rule->verified_by)<p class="mt-1 text-xs text-muted">{{ $rule->verified_by }} · {{ $rule->verified_at?->format('d.m.Y') }}</p>@endif
                            </td>
                            <td class="td text-right">
                                @if ($staff->isSuperAdmin())
                                    <div class="flex justify-end gap-1">
                                        @unless ($rule->isVerified())
                                            <form method="post" action="{{ platform_route('tax-rules.rule.verify', $rule) }}">@csrf
                                                <x-ui.confirm-button :message="__('platform.tax_rules.confirm_verify', ['code' => $rule->code, 'value' => $rule->valueLabel()])" variant="soft" icon="check">{{ __('platform.tax_rules.verify') }}</x-ui.confirm-button>
                                            </form>
                                        @endunless
                                        <x-ui.button size="sm" variant="ghost" icon="pencil" @click="edit = !edit">{{ __('platform.common.edit') }}</x-ui.button>
                                    </div>
                                    <form x-cloak x-show="edit" method="post" action="{{ platform_route('tax-rules.rule', $rule) }}" class="mt-2 space-y-2 text-left">
                                        @csrf @method('PUT')
                                        <x-ui.input name="value" :id="'value_'.$rule->id" :label="$rule->kind === 'rate' ? __('platform.tax_rules.value_percent') : __('platform.tax_rules.value_amount')"
                                                    :value="rtrim(rtrim(number_format($rule->value / 100, 2, '.', ''), '0'), '.')" />
                                        <x-ui.input name="effective_to" :id="'to_'.$rule->id" type="date" :label="__('platform.tax_rules.effective_to')" :value="$rule->effective_to?->toDateString()" />
                                        <x-ui.textarea name="source" :id="'source_'.$rule->id" :label="__('platform.tax_rules.source')" :value="$rule->source" rows="2" />
                                        <x-ui.button type="submit" size="sm">{{ __('platform.common.save') }}</x-ui.button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <h2 class="mb-3 mt-8 text-lg font-semibold">{{ __('platform.tax_rules.deadlines') }}</h2>
    <x-ui.card padding="p-0">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[52rem]">
                <thead><tr class="border-b border-line">
                    <th class="th">{{ __('platform.tax_rules.report') }}</th>
                    <th class="th">{{ __('platform.tax_rules.due') }}</th>
                    <th class="th">{{ __('platform.tax_rules.source') }}</th>
                    <th class="th">{{ __('platform.tax_rules.status') }}</th>
                    <th class="th"></th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($deadlines as $deadline)
                        <tr x-data="{ edit: false }">
                            <td class="td font-semibold">{{ __('tax.types.'.$deadline->report_type) }}<p class="text-xs font-normal text-muted">{{ __('platform.tax_rules.periods.'.$deadline->period) }} · v{{ $deadline->version }}</p></td>
                            <td class="td">{{ __('platform.tax_rules.due_text', ['day' => $deadline->day, 'months' => $deadline->months_after]) }}</td>
                            <td class="td max-w-sm text-xs text-muted">{{ $deadline->source }}</td>
                            <td class="td"><x-ui.badge :tone="$deadline->isVerified() ? 'success' : 'warning'">{{ $deadline->isVerified() ? __('platform.tax_rules.verified_badge') : __('platform.tax_rules.unverified_badge') }}</x-ui.badge></td>
                            <td class="td text-right">
                                @if ($staff->isSuperAdmin())
                                    <div class="flex justify-end gap-1">
                                        @unless ($deadline->isVerified())
                                            <form method="post" action="{{ platform_route('tax-rules.deadline.verify', $deadline) }}">@csrf
                                                <x-ui.confirm-button :message="__('platform.tax_rules.confirm_verify_deadline')" variant="soft" icon="check">{{ __('platform.tax_rules.verify') }}</x-ui.confirm-button>
                                            </form>
                                        @endunless
                                        <x-ui.button size="sm" variant="ghost" icon="pencil" @click="edit = !edit">{{ __('platform.common.edit') }}</x-ui.button>
                                    </div>
                                    <form x-cloak x-show="edit" method="post" action="{{ platform_route('tax-rules.deadline', $deadline) }}" class="mt-2 grid grid-cols-2 gap-2 text-left">
                                        @csrf @method('PUT')
                                        <x-ui.input name="months_after" :id="'m_'.$deadline->id" type="number" min="0" max="12" :label="__('platform.tax_rules.months_after')" :value="$deadline->months_after" />
                                        <x-ui.input name="day" :id="'d_'.$deadline->id" type="number" min="1" max="31" :label="__('platform.tax_rules.day')" :value="$deadline->day" />
                                        <x-ui.textarea class="col-span-2" name="source" :id="'s_'.$deadline->id" :label="__('platform.tax_rules.source')" :value="$deadline->source" rows="2" />
                                        <div class="col-span-2"><x-ui.button type="submit" size="sm">{{ __('platform.common.save') }}</x-ui.button></div>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
    <p class="mt-4 max-w-3xl text-xs text-muted">{{ __('platform.tax_rules.note') }}</p>
</x-layouts.platform>
