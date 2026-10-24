<x-layouts.app :title="__('ui.nav.signing_keys')">
    <x-ui.page-header :title="__('autopilot.keys.title')" :subtitle="__('autopilot.keys.subtitle')">
        <x-slot:actions>
            @can('autopilot.manage')
                <x-ui.button :href="route('autopilot.show')" variant="secondary" icon="shield">{{ __('ui.nav.autopilot') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-ui.card padding="p-0">
                @if ($keys->isEmpty())
                    <x-ui.empty-state :title="__('autopilot.keys.empty')" :text="__('autopilot.keys.empty_text')" icon="key" />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[40rem]">
                            <thead><tr class="border-b border-line">
                                <th class="th">{{ __('autopilot.keys.fields.label') }}</th>
                                <th class="th">{{ __('autopilot.keys.fields.owner_name') }}</th>
                                <th class="th">{{ __('autopilot.keys.fields.valid_to') }}</th>
                                <th class="th">{{ __('ui.common.status') }}</th>
                                <th class="th"></th>
                            </tr></thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($keys as $key)
                                    <tr>
                                        <td class="td"><p class="font-semibold">{{ $key->label }}</p><p class="font-mono text-xs text-muted">{{ substr($key->fingerprint, 0, 16) }}…</p></td>
                                        <td class="td">{{ $key->owner_name }}<p class="text-xs text-muted">{{ $key->owner_tin ?? '—' }} · {{ $key->serial_number ?? '—' }}</p></td>
                                        <td class="td tabular-nums">{{ $key->valid_to->format('d.m.Y') }}
                                            @if ($key->isUsable())<p @class(['text-xs', 'text-amber-700' => $key->daysLeft() <= 30, 'text-muted' => $key->daysLeft() > 30])>{{ __('autopilot.keys.days_left', ['days' => $key->daysLeft()]) }}</p>@endif</td>
                                        <td class="td"><x-ui.badge :tone="$key->isUsable() ? 'success' : 'neutral'">{{ $key->statusLabel() }}</x-ui.badge></td>
                                        <td class="td text-right">
                                            @if ($key->status === 'active')
                                                <form method="post" action="{{ route('signing-keys.revoke', $key) }}">@csrf
                                                    <x-ui.confirm-button :message="__('autopilot.keys.confirm_revoke')" class="!text-red-600">{{ __('autopilot.keys.revoke') }}</x-ui.confirm-button>
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

            <x-ui.card padding="p-0">
                <div class="px-5 pt-5 sm:px-6"><h2 class="font-semibold">{{ __('autopilot.keys.log') }}</h2><p class="text-xs text-muted">{{ __('autopilot.keys.log_hint') }}</p></div>
                @if ($signatures->isEmpty())
                    <p class="px-6 py-6 text-sm text-muted">{{ __('autopilot.keys.log_empty') }}</p>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[40rem]">
                            <thead><tr class="border-b border-line">
                                <th class="th">{{ __('ui.audit.when') }}</th>
                                <th class="th">{{ __('tax.report') }}</th>
                                <th class="th">{{ __('ui.audit.who') }}</th>
                                <th class="th">{{ __('tax.signature') }}</th>
                            </tr></thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($signatures as $log)
                                    <tr>
                                        <td class="td tabular-nums">{{ $log->created_at->format('d.m.Y H:i') }}</td>
                                        <td class="td">@if ($log->report)<a class="font-semibold text-brand-700 hover:underline" href="{{ route('tax.reports.show', $log->report) }}">{{ $log->report->title() }}</a>@else — @endif</td>
                                        <td class="td">{{ $log->actor_name }} <span class="text-xs text-muted">({{ __('ui.actors.'.$log->actor_type) }})</span><p class="text-xs text-muted">{{ $log->approval_ref }}</p></td>
                                        <td class="td text-xs">{{ __('tax.signers.'.$log->signer) }}<p class="font-mono text-muted">{{ substr($log->document_hash, 0, 16) }}…</p></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card>
            <h2 class="mb-1 font-semibold">{{ __('autopilot.keys.upload') }}</h2>
            <p class="mb-4 text-xs text-muted">{{ __('autopilot.keys.upload_hint') }}</p>
            <form method="post" action="{{ route('signing-keys.store') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <label for="key_file" class="label">{{ __('autopilot.keys.fields.key_file') }} <span class="text-red-500">*</span></label>
                    <input id="key_file" type="file" name="key_file" accept=".pfx,.p12" required class="field file:mr-3 file:rounded-full file:border-0 file:bg-brand-50 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-brand-700">
                    @error('key_file')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                {{-- The key's own password: never let the browser fill in the login password here. --}}
                <x-ui.input name="password" type="password" autocomplete="new-password" data-1p-ignore data-lpignore="true" :label="__('autopilot.keys.fields.password')" required />
                <x-ui.input name="label" :label="__('autopilot.keys.fields.label')" autocomplete="off" required />
                <x-ui.input name="owner_name" :label="__('autopilot.keys.fields.owner_name')" :value="auth()->user()->name" required />
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input name="owner_tin" inputmode="numeric" maxlength="14" :label="__('autopilot.keys.fields.owner_tin')" />
                    <x-ui.input name="serial_number" :label="__('autopilot.keys.fields.serial_number')" />
                    <x-ui.input name="valid_from" type="date" :label="__('autopilot.keys.fields.valid_from')" />
                    <x-ui.input name="valid_to" type="date" :label="__('autopilot.keys.fields.valid_to')" required />
                </div>
                <x-ui.button type="submit" class="w-full" icon="lock">{{ __('autopilot.keys.store') }}</x-ui.button>
            </form>
            <p class="mt-4 text-xs text-muted">{{ __('autopilot.keys.security_note') }}</p>
        </x-ui.card>
    </div>
</x-layouts.app>
