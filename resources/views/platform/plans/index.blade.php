<x-layouts.platform :title="__('platform.nav.plans')">
    <x-ui.page-header :title="__('platform.plans.title')" :subtitle="__('platform.plans.subtitle')">
        <x-slot:actions>
            @if (auth('platform')->user()->isSuperAdmin())
                <x-ui.button :href="platform_route('plans.create')" icon="plus">{{ __('platform.plans.new') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($plans->isEmpty())
        <x-ui.card><x-ui.empty-state :title="__('platform.plans.empty')" icon="receipt" /></x-ui.card>
    @else
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($plans as $plan)
                <x-ui.card :class="$plan->is_active ? '' : 'opacity-60'">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-lg font-semibold">{{ $plan->name }}</p>
                            <p class="font-mono text-xs text-muted">{{ $plan->key }}</p>
                        </div>
                        <x-ui.badge :tone="$plan->is_active ? 'success' : 'neutral'">{{ $plan->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</x-ui.badge>
                    </div>
                    <p class="amount mt-4 text-2xl font-semibold tracking-tight">{{ $plan->priceLabel() }}</p>
                    @if ($plan->description)<p class="mt-1 text-sm text-muted">{{ $plan->description }}</p>@endif

                    <dl class="mt-4 space-y-1.5 border-t border-line pt-4 text-sm">
                        @foreach (\App\Http\Requests\Platform\PlanRequest::LIMITS as $limit)
                            <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('platform.plans.limits.'.$limit) }}</dt><dd class="font-semibold tabular-nums">{{ $plan->limit($limit) ?? __('platform.plans.unlimited') }}</dd></div>
                        @endforeach
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('platform.plans.companies') }}</dt><dd class="font-semibold tabular-nums">{{ $plan->tenants_count }}</dd></div>
                    </dl>

                    @if (auth('platform')->user()->isSuperAdmin())
                        <x-ui.button :href="platform_route('plans.edit', ['plan' => $plan->id])" variant="secondary" size="sm" icon="pencil" class="mt-4">{{ __('platform.common.edit') }}</x-ui.button>
                    @endif
                </x-ui.card>
            @endforeach
        </div>
    @endif
</x-layouts.platform>
