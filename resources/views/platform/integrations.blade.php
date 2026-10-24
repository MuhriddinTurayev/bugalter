<x-layouts.platform :title="__('platform.integrations.title')">
    <x-ui.page-header :title="__('platform.integrations.title')" :subtitle="__('platform.integrations.subtitle')" />

    <h2 class="mb-3 text-lg font-semibold">{{ __('platform.integrations.ai_title') }}</h2>
    <p class="mb-4 max-w-3xl text-sm text-muted">{{ __('platform.integrations.ai_intro') }}</p>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        @foreach ($providers as $provider)
            @php $row = $ai['providers'][$provider->value]; @endphp
            <x-ui.card>
                <form method="post" action="{{ platform_route('integrations.ai', $provider->value) }}" class="space-y-3">
                    @csrf @method('PUT')
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold">{{ $provider->label() }}</h3>
                        <div class="flex gap-1.5">
                            @if ($ai['default'] === $provider->value)<x-ui.badge tone="success">{{ __('platform.integrations.default') }}</x-ui.badge>@endif
                            <x-ui.badge :tone="$row['has_key'] ? 'info' : 'neutral'">{{ $row['has_key'] ? __('platform.integrations.key_set') : __('platform.integrations.key_missing') }}</x-ui.badge>
                        </div>
                    </div>

                    <input type="hidden" name="enabled" value="0">
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input type="checkbox" name="enabled" value="1" @checked($row['enabled']) class="size-4 rounded border-line text-brand-600"> {{ __('platform.integrations.enabled') }}
                    </label>

                    <x-ui.input :name="'api_key'" :id="'api_key_'.$provider->value" type="password" autocomplete="new-password" data-1p-ignore data-lpignore="true" :label="__('platform.integrations.api_key')"
                                :placeholder="$row['has_key'] ? __('platform.integrations.key_keep') : __('platform.integrations.key_new')" />
                    <p class="-mt-2 text-xs"><a href="{{ $provider->keysUrl() }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 hover:underline">{{ __('platform.integrations.get_key') }}</a></p>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <x-ui.input name="model" :id="'model_'.$provider->value" :label="__('platform.integrations.model')" :value="$row['model']" autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true" />
                        <x-ui.input name="base_url" :id="'base_url_'.$provider->value" :label="__('platform.integrations.base_url')" :value="$row['base_url']" autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true" />
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <input type="hidden" name="is_default" value="0">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_default" value="1" @checked($ai['default'] === $provider->value) class="size-4 rounded border-line text-brand-600"> {{ __('platform.integrations.make_default') }}</label>
                        @if ($row['has_key'])
                            <input type="hidden" name="remove_key" value="0">
                            <label class="flex items-center gap-2 text-sm text-red-600"><input type="checkbox" name="remove_key" value="1" class="size-4 rounded border-line text-red-600"> {{ __('platform.integrations.remove_key') }}</label>
                        @endif
                    </div>

                    <x-ui.button type="submit" size="sm">{{ __('platform.common.save') }}</x-ui.button>
                </form>
                @if ($row['has_key'])
                    <form method="post" action="{{ platform_route('integrations.ai.test', $provider->value) }}" class="mt-2">@csrf
                        <x-ui.button type="submit" size="sm" variant="soft" icon="send">{{ __('platform.integrations.test') }}</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    <div class="mt-8 grid grid-cols-1 gap-5 lg:grid-cols-2">
        <x-ui.card>
            <h2 class="mb-1 font-semibold">{{ __('platform.integrations.gateway_title') }}</h2>
            <p class="mb-4 text-sm text-muted">{{ __('platform.integrations.gateway_intro') }}</p>
            <form method="post" action="{{ platform_route('integrations.gateway') }}" class="space-y-3" x-data="{ driver: @js(old('driver', $gateway['driver'])) }">
                @csrf @method('PUT')
                <x-ui.select name="driver" :label="__('platform.integrations.gateway_fields.driver')" x-model="driver">
                    <option value="sandbox">{{ __('platform.integrations.drivers.sandbox') }}</option>
                    <option value="http">{{ __('platform.integrations.drivers.http') }}</option>
                    <option value="portal">{{ __('platform.integrations.drivers.portal') }}</option>
                    <option value="desktop">{{ __('platform.integrations.drivers.desktop') }}</option>
                </x-ui.select>
                <p x-show="driver === 'portal'" x-cloak class="text-xs text-muted">{{ __('platform.integrations.portal_gateway_hint') }}</p>
                <p x-show="driver === 'desktop'" x-cloak class="text-xs text-muted">{{ __('platform.integrations.desktop_gateway_hint') }}</p>
                <div x-show="driver === 'http'" x-cloak class="space-y-3">
                    <x-ui.input name="base_url" :label="__('platform.integrations.gateway_fields.base_url')" :value="$gateway['base_url']" placeholder="https://" />
                    <x-ui.input name="submit_path" :label="__('platform.integrations.gateway_fields.submit_path')" :value="$gateway['submit_path']" placeholder="/reports" />
                    <x-ui.input name="status_path" :label="__('platform.integrations.gateway_fields.status_path')" :value="$gateway['status_path']" placeholder="/reports/status" :hint="__('platform.integrations.status_path_hint')" />
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <x-ui.input name="client_id" :label="__('platform.integrations.gateway_fields.client_id')" :value="$gateway['client_id']" autocomplete="off" data-1p-ignore data-lpignore="true" />
                        <x-ui.input name="client_secret" type="password" autocomplete="new-password" data-1p-ignore data-lpignore="true" :label="__('platform.integrations.gateway_fields.client_secret')" :placeholder="$gateway['has_secret'] ? __('platform.integrations.key_keep') : ''" />
                    </div>
                </div>
                <x-ui.input name="timeout" type="number" min="5" max="120" :label="__('platform.integrations.gateway_fields.timeout')" :value="$gateway['timeout']" />
                <x-ui.button type="submit" size="sm">{{ __('platform.common.save') }}</x-ui.button>
            </form>
            <p class="mt-4 text-xs text-muted">{{ __('platform.integrations.gateway_contract') }}</p>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-1 font-semibold">{{ __('platform.integrations.signer_title') }}</h2>
            <p class="mb-4 text-sm text-muted">{{ __('platform.integrations.signer_intro') }}</p>
            <form method="post" action="{{ platform_route('integrations.signer') }}" class="space-y-3" x-data="{ engine: @js(old('engine', $signer['engine'])) }">
                @csrf @method('PUT')
                <x-ui.select name="engine" :label="__('platform.integrations.signer_fields.engine')" x-model="engine">
                    <option value="sandbox">{{ __('platform.integrations.engines.sandbox') }}</option>
                    <option value="remote">{{ __('platform.integrations.engines.remote') }}</option>
                </x-ui.select>
                <div x-show="engine === 'remote'" x-cloak class="space-y-3">
                    <x-ui.input name="url" :label="__('platform.integrations.signer_fields.url')" :value="$signer['url']" placeholder="https://" autocomplete="off" data-1p-ignore data-lpignore="true" />
                    <x-ui.input name="token" type="password" autocomplete="new-password" data-1p-ignore data-lpignore="true" :label="__('platform.integrations.signer_fields.token')" :placeholder="$signer['has_token'] ? __('platform.integrations.key_keep') : ''" />
                </div>
                <x-ui.button type="submit" size="sm">{{ __('platform.common.save') }}</x-ui.button>
            </form>
            <p class="mt-4 text-xs text-amber-700">{{ __('platform.integrations.legal_note') }}</p>
        </x-ui.card>

        <x-ui.card class="lg:col-span-2">
            <h2 class="mb-1 font-semibold">{{ __('platform.integrations.portal_title') }}</h2>
            <p class="mb-4 text-sm text-muted">{{ __('platform.integrations.portal_intro') }}</p>
            <form method="post" action="{{ platform_route('integrations.portal') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2" x-data="{ driver: @js(old('driver', $portal['driver'])) }">
                @csrf @method('PUT')
                <x-ui.select name="driver" :label="__('platform.integrations.portal_fields.driver')" x-model="driver">
                    <option value="sandbox">{{ __('platform.integrations.portal_drivers.sandbox') }}</option>
                    <option value="robot">{{ __('platform.integrations.portal_drivers.robot') }}</option>
                </x-ui.select>
                <x-ui.input name="timeout" type="number" min="30" max="600" :label="__('platform.integrations.portal_fields.timeout')" :value="$portal['timeout']" />
                <div x-show="driver === 'robot'" x-cloak class="contents">
                    <x-ui.input name="url" :label="__('platform.integrations.portal_fields.url')" :value="$portal['url']" placeholder="https://" autocomplete="off" data-1p-ignore data-lpignore="true" />
                    <x-ui.input name="token" type="password" autocomplete="new-password" data-1p-ignore data-lpignore="true" :label="__('platform.integrations.portal_fields.token')" :placeholder="$portal['has_token'] ? __('platform.integrations.key_keep') : ''" />
                </div>
                <div class="sm:col-span-2"><x-ui.button type="submit" size="sm">{{ __('platform.common.save') }}</x-ui.button></div>
            </form>
            <p class="mt-4 text-xs text-muted">{{ __('platform.integrations.portal_contract') }}</p>
        </x-ui.card>
    </div>
</x-layouts.platform>
