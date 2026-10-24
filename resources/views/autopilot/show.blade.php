@php
    use App\Enums\AutopilotLevel;
    use App\Support\Money;

    $auto = $settings->autoApply(\App\Models\AiProposal::CLASSIFY_TRANSACTION);
@endphp

<x-layouts.app :title="__('autopilot.title')">
    <x-ui.page-header :title="__('autopilot.title')" :subtitle="__('autopilot.subtitle')">
        <x-slot:actions>
            @can('signing.keys.manage')
                <x-ui.button :href="route('signing-keys.index')" variant="secondary" icon="key">{{ __('ui.nav.signing_keys') }}</x-ui.button>
            @endcan
            <form method="post" action="{{ route('autopilot.kill-switch') }}">@csrf
                <x-ui.confirm-button :message="__('autopilot.confirm_kill_switch')" variant="danger" size="md" icon="power">{{ __('autopilot.kill_switch') }}</x-ui.confirm-button>
            </form>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($platformKillSwitch)
        <div class="mb-5 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">{{ __('autopilot.platform_kill_switch') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <form method="post" action="{{ route('autopilot.update') }}" class="space-y-5 xl:col-span-2" x-data="{ level: @js((string) old('autopilot_level', $settings->autopilot()->value)) }">
            @csrf @method('PUT')

            <x-ui.card>
                <h2 class="mb-4 font-semibold">{{ __('autopilot.level') }}</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($levels as $level)
                        @php $locked = $level->needsMandate() && ! $hasMandate; @endphp
                        <label @class(['flex cursor-pointer gap-3 rounded-2xl p-4 ring-1 transition', 'opacity-60' => $locked])
                               :class="level === @js((string) $level->value) ? 'bg-brand-50 ring-brand-500' : 'ring-line hover:bg-soft'">
                            <input type="radio" name="autopilot_level" value="{{ $level->value }}" x-model="level" class="mt-1 size-4 text-brand-600" @disabled($locked)>
                            <span>
                                <span class="block font-semibold">{{ $level->value }} · {{ $level->label() }}</span>
                                <span class="block text-xs text-muted">{{ $level->description() }}</span>
                                @if ($locked)<span class="mt-1 block text-xs font-semibold text-amber-700">{{ __('autopilot.needs_mandate') }}</span>@endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('autopilot_level')<p class="mt-3 text-sm font-medium text-red-600">{{ $message }}</p>@enderror
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-4 font-semibold">{{ __('autopilot.settings') }}</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input name="cancel_window_hours" type="number" min="1" max="168" :label="__('autopilot.cancel_window')" :value="$settings->cancel_window_hours" :hint="__('autopilot.cancel_window_hint')" required />
                    <x-ui.select name="signer_mode" :label="__('autopilot.signer_mode')" :value="$settings->signer_mode">
                        <option value="vault" @selected($settings->signer_mode === 'vault')>{{ __('autopilot.signer_modes.vault') }}</option>
                        <option value="device" @selected($settings->signer_mode === 'device')>{{ __('autopilot.signer_modes.device') }}</option>
                    </x-ui.select>
                </div>
                @if (! $hasKey)
                    <p class="mt-3 text-xs text-amber-700">{{ __('autopilot.no_key_hint') }}</p>
                @endif

                <div class="mt-5 rounded-2xl bg-soft p-4">
                    <input type="hidden" name="portal_access" value="0">
                    <label class="flex items-start gap-2 text-sm font-medium">
                        <input type="checkbox" name="portal_access" value="1" @checked($settings->portal_access) class="mt-0.5 size-4 rounded border-line text-brand-600">
                        <span>{{ __('autopilot.portal_access') }}<span class="block text-xs font-normal text-muted">{{ __('autopilot.portal_access_hint') }}</span>
                            @if ($settings->portal_access && $settings->portal_access_by)
                                <span class="mt-1 block text-xs font-normal text-brand-700">{{ __('autopilot.portal_access_given', ['name' => $settings->portal_access_by, 'date' => $settings->portal_access_at?->format('d.m.Y H:i')]) }}</span>
                            @endif
                        </span>
                    </label>
                </div>

                <div class="mt-3 rounded-2xl bg-soft p-4">
                    <input type="hidden" name="auto_apply_classification" value="0">
                    <label class="flex items-start gap-2 text-sm font-medium">
                        <input type="checkbox" name="auto_apply_classification" value="1" @checked($auto['enabled']) class="mt-0.5 size-4 rounded border-line text-brand-600">
                        <span>{{ __('autopilot.auto_apply') }}<span class="block text-xs font-normal text-muted">{{ __('autopilot.auto_apply_hint') }}</span></span>
                    </label>
                    <x-ui.input class="mt-3 max-w-xs" name="auto_apply_min_confidence" type="number" min="50" max="100" :label="__('autopilot.min_confidence')" :value="$auto['min_confidence']" />
                </div>
            </x-ui.card>

            <div class="flex justify-end"><x-ui.button type="submit">{{ __('ui.common.save') }}</x-ui.button></div>
        </form>

        <div class="space-y-5">
            <x-ui.card>
                <p class="label">{{ __('autopilot.how_it_works') }}</p>
                <ol class="list-decimal space-y-1.5 pl-4 text-sm text-muted">
                    @foreach (__('autopilot.steps') as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </x-ui.card>
            <p class="text-xs text-muted">{{ __('autopilot.legal_note') }}</p>
        </div>
    </div>

    {{-- Mandates --}}
    <div class="mt-8 grid grid-cols-1 gap-5 xl:grid-cols-3">
        <x-ui.card padding="p-0" class="xl:col-span-2">
            <div class="px-5 pt-5 sm:px-6">
                <h2 class="font-semibold">{{ __('autopilot.mandate.title') }}</h2>
                <p class="text-xs text-muted">{{ __('autopilot.mandate.hint') }}</p>
            </div>
            @if ($mandates->isEmpty())
                <x-ui.empty-state :title="__('autopilot.mandate.empty')" icon="signature" />
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[44rem]">
                        <thead><tr class="border-b border-line">
                            <th class="th">#</th>
                            <th class="th">{{ __('autopilot.mandate.fields.report_types') }}</th>
                            <th class="th">{{ __('autopilot.mandate.periods') }}</th>
                            <th class="th text-right">{{ __('autopilot.mandate.fields.amount_limit') }}</th>
                            <th class="th">{{ __('autopilot.mandate.fields.expires_at') }}</th>
                            <th class="th">{{ __('ui.common.status') }}</th>
                            <th class="th"></th>
                        </tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($mandates as $mandate)
                                <tr>
                                    <td class="td text-muted">{{ $mandate->id }}</td>
                                    <td class="td">{{ collect($mandate->report_types)->map(fn ($t) => __('tax.types.'.$t))->implode(', ') }}
                                        <p class="text-xs text-muted">{{ __('autopilot.mandate.signed_as', ['name' => $mandate->signed_name]) }} · {{ $mandate->signed_at->format('d.m.Y') }}</p></td>
                                    <td class="td tabular-nums">{{ $mandate->period_from->format('m.Y') }} — {{ $mandate->period_to->format('m.Y') }}</td>
                                    <td class="td amount text-right">{{ Money::format($mandate->amount_limit_minor) }}@if ($mandate->change_limit_percent)<p class="text-xs text-muted">±{{ $mandate->change_limit_percent }}%</p>@endif</td>
                                    <td class="td tabular-nums">{{ $mandate->expires_at->format('d.m.Y') }}</td>
                                    <td class="td"><x-ui.badge :tone="$mandate->isActive() ? 'success' : 'neutral'">{{ $mandate->statusLabel() }}</x-ui.badge></td>
                                    <td class="td text-right">
                                        @if ($mandate->isActive())
                                            <form method="post" action="{{ route('autopilot.mandates.revoke', $mandate) }}">@csrf
                                                <x-ui.confirm-button :message="__('autopilot.mandate.confirm_revoke')" class="!text-red-600">{{ __('autopilot.mandate.revoke') }}</x-ui.confirm-button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-1 font-semibold">{{ __('autopilot.mandate.new') }}</h2>
            <p class="mb-4 text-xs text-muted">{{ __('autopilot.mandate.new_hint') }}</p>
            <form method="post" action="{{ route('autopilot.mandates.store') }}" class="space-y-3">
                @csrf
                <div>
                    <p class="label">{{ __('autopilot.mandate.fields.report_types') }}</p>
                    @foreach ($reportTypes as $type)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="report_types[]" value="{{ $type->value }}" @checked(in_array($type->value, old('report_types', []), true)) class="size-4 rounded border-line text-brand-600"> {{ $type->label() }}</label>
                    @endforeach
                    @error('report_types')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input name="period_from" type="date" :label="__('autopilot.mandate.fields.period_from')" :value="now()->startOfYear()->toDateString()" required />
                    <x-ui.input name="period_to" type="date" :label="__('autopilot.mandate.fields.period_to')" :value="now()->endOfYear()->toDateString()" required />
                </div>
                <x-ui.input name="amount_limit" inputmode="decimal" :label="__('autopilot.mandate.fields.amount_limit')" :hint="__('autopilot.mandate.amount_hint')" required />
                <x-ui.input name="change_limit_percent" type="number" min="1" max="1000" :label="__('autopilot.mandate.fields.change_limit_percent')" :hint="__('autopilot.mandate.change_hint')" />
                <x-ui.input name="expires_at" type="date" :label="__('autopilot.mandate.fields.expires_at')" :value="now()->addYear()->toDateString()" required />
                <x-ui.input name="signed_name" :label="__('autopilot.mandate.fields.signed_name')" :placeholder="auth()->user()->name" required />
                <label class="flex items-start gap-2 text-xs">
                    <input type="checkbox" name="confirm" value="1" class="mt-0.5 size-4 rounded border-line text-brand-600">
                    <span>{{ __('autopilot.mandate.confirm_text') }}</span>
                </label>
                @error('confirm')<p class="text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                <x-ui.button type="submit" class="w-full" icon="signature">{{ __('autopilot.mandate.sign') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
