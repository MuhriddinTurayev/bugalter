@php
    $slugUrl = platform_route('companies.slug-check');
@endphp

<x-layouts.platform :title="__('platform.companies.new')">
    <x-ui.page-header :title="__('platform.companies.new')" :subtitle="__('platform.companies.new_subtitle')">
        <x-slot:actions><x-ui.button :href="platform_route('companies.index')" variant="secondary">{{ __('platform.common.back') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <form method="post" action="{{ platform_route('companies.store') }}" class="max-w-3xl space-y-5"
          x-data="companySlug({ url: @js($slugUrl), slug: @js(old('slug', '')), name: @js(old('name', '')) })">
        @csrf

        <x-ui.card>
            <h2 class="mb-4 font-semibold">{{ __('platform.companies.company') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="label">{{ __('platform.companies.fields.name') }}<span class="text-red-500"> *</span></label>
                    <input id="name" name="name" class="field {{ $errors->has('name') ? 'field-invalid' : '' }}" x-model="name" @input="suggest()" required maxlength="255">
                    @error('name')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-ui.input name="stir" :label="__('platform.companies.fields.stir')" inputmode="numeric" maxlength="9" />
                <x-ui.select name="plan_id" :label="__('platform.companies.fields.plan_id')" required>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" @selected((int) old('plan_id') === $plan->id)>{{ $plan->name }}</option>
                    @endforeach
                </x-ui.select>

                <div class="sm:col-span-2">
                    <label for="slug" class="label">{{ __('platform.companies.fields.slug') }}<span class="text-red-500"> *</span></label>
                    <div class="flex items-stretch overflow-hidden rounded-xl border border-line bg-white focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-500/15 {{ $errors->has('slug') ? 'border-red-400' : '' }}">
                        <input id="slug" name="slug" class="min-w-0 flex-1 border-0 bg-transparent px-3.5 py-2.5 text-sm outline-none" x-model="slug" @input="touched = true; check()" required maxlength="40" autocomplete="off" spellcheck="false">
                        <span class="flex items-center bg-soft px-3 text-sm text-muted">.{{ $domain }}</span>
                    </div>
                    <p class="mt-1 text-xs font-medium text-muted" x-cloak x-show="state === 'checking'">{{ __('platform.companies.slug_checking') }}</p>
                    <p class="mt-1 text-xs font-medium text-brand-700" x-cloak x-show="state === 'ok'" x-text="message"></p>
                    <p class="mt-1 text-xs font-medium text-red-600" x-cloak x-show="state === 'bad'" x-text="message"></p>
                    @error('slug')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-muted">{{ __('platform.companies.slug_hint', ['domain' => $domain]) }}</p>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-1 font-semibold">{{ __('platform.companies.owner') }}</h2>
            <p class="mb-4 text-sm text-muted">{{ __('platform.companies.owner_hint') }}</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input class="sm:col-span-2" name="owner_name" :label="__('platform.companies.fields.owner_name')" required />
                <x-ui.input name="owner_email" type="email" :label="__('platform.companies.fields.owner_email')" required />
                <x-ui.input name="owner_phone" type="tel" :label="__('platform.companies.fields.owner_phone')" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="platform_route('companies.index')" variant="ghost">{{ __('platform.common.cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="dark" x-bind:disabled="state === 'bad'" icon="plus">{{ __('platform.companies.create') }}</x-ui.button>
        </div>
    </form>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('companySlug', ({ url, slug, name }) => ({
                url, slug, name, touched: slug !== '', state: 'idle', message: '', timer: null,
                init() { if (this.slug) this.check(); },
                // Suggests a slug from the company name until the user edits the slug by hand.
                suggest() {
                    if (this.touched) return;
                    this.slug = this.name.toLowerCase().normalize('NFKD').replace(/[ʻʼ'’`]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
                    this.check();
                },
                check() {
                    clearTimeout(this.timer);
                    if (!this.slug) { this.state = 'idle'; return; }
                    this.state = 'checking';
                    this.timer = setTimeout(async () => {
                        try {
                            const r = await fetch(`${this.url}?slug=${encodeURIComponent(this.slug)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                            const data = await r.json();
                            this.state = data.available ? 'ok' : 'bad';
                            this.message = data.message;
                        } catch (e) { this.state = 'idle'; }
                    }, 300);
                },
            }));
        });
    </script>
</x-layouts.platform>
