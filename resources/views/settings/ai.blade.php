<x-layouts.app :title="__('ui.settings.ai')">
    @include('settings._nav')

    <div class="grid grid-cols-1 max-w-5xl gap-5 lg:grid-cols-3">
        <form method="post" action="{{ route('settings.ai.update') }}" class="space-y-5 lg:col-span-2" autocomplete="off"
              x-data="{ provider: @js(old('ai_provider', $settings->ai_provider ?? '')), defaults: @js(collect($providers)->mapWithKeys(fn ($p) => [$p->value => $p->defaultModel()])), fallback: @js(__('ai.settings.model_placeholder')) }">
            @csrf @method('PUT')

            <x-ui.card>
                <h2 class="mb-1 font-semibold">{{ __('ai.settings.title') }}</h2>
                <p class="mb-4 text-sm text-muted">{{ __('ai.settings.intro') }}</p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.select name="ai_provider" :label="__('ai.settings.provider')" x-model="provider">
                        <option value="">{{ __('ai.settings.platform_default') }}</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->value }}" @selected($settings->ai_provider === $provider->value)>{{ $provider->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    {{-- Browsers must not fill a saved login into these fields: the model is not a username, the key not a password. --}}
                    <x-ui.input name="ai_model" :label="__('ai.settings.model')" :value="$settings->ai_model" :placeholder="__('ai.settings.model_placeholder')"
                                x-bind:placeholder="defaults[provider] ?? fallback" :hint="__('ai.settings.model_hint')"
                                autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true" />
                </div>

                <div class="mt-4" x-show="provider !== ''" x-cloak>
                    <x-ui.input name="ai_api_key" type="password" autocomplete="new-password" data-1p-ignore data-lpignore="true" :label="__('ai.settings.api_key')"
                                :placeholder="$hasOwnKey ? __('ai.settings.key_saved') : __('ai.settings.key_placeholder')" :hint="__('ai.settings.key_hint')" />
                    @foreach ($providers as $provider)
                        <p class="mt-1 text-xs text-muted" x-show="provider === @js($provider->value)">
                            <a href="{{ $provider->keysUrl() }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 hover:underline">{{ __('ai.settings.get_key', ['provider' => $provider->label()]) }}</a>
                        </p>
                    @endforeach
                    @if ($hasOwnKey)
                        <label class="mt-3 flex items-center gap-2 text-sm">
                            <input type="hidden" name="remove_key" value="0">
                            <input type="checkbox" name="remove_key" value="1" class="size-4 rounded border-line text-red-600"> {{ __('ai.settings.remove_key') }}
                        </label>
                    @endif
                </div>
            </x-ui.card>

            <div class="flex justify-end"><x-ui.button type="submit">{{ __('ui.common.save') }}</x-ui.button></div>
        </form>

        <div class="space-y-5">
            <x-ui.card>
                <p class="label">{{ __('ai.settings.effective') }}</p>
                @if ($effective)
                    <p class="font-semibold">{{ $effective->provider->label() }}</p>
                    <p class="text-sm text-muted">{{ $effective->model }}</p>
                    <x-ui.badge :tone="$effective->source === 'company' ? 'success' : 'info'" class="mt-2">{{ __('ai.settings.source_'.$effective->source) }}</x-ui.badge>
                    <form method="post" action="{{ route('settings.ai.test') }}" class="mt-4">@csrf
                        <x-ui.button type="submit" variant="soft" size="sm" icon="send">{{ __('ai.settings.test') }}</x-ui.button>
                    </form>
                @else
                    <p class="text-sm text-amber-700">{{ __('ai.settings.not_configured') }}</p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <p class="label">{{ __('ai.settings.platform_providers') }}</p>
                @forelse ($platformProviders as $provider)
                    <p class="text-sm">{{ $provider->label() }} @if ($platform['default'] === $provider->value)<x-ui.badge tone="success">{{ __('ai.settings.default') }}</x-ui.badge>@endif</p>
                @empty
                    <p class="text-sm text-muted">{{ __('ai.settings.no_platform_providers') }}</p>
                @endforelse
            </x-ui.card>

            <p class="text-xs text-muted">{{ __('ai.settings.privacy') }}</p>
        </div>
    </div>
</x-layouts.app>
