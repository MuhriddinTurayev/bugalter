<x-layouts.platform :title="__('platform.nav.dashboard')">
    <x-ui.page-header :title="__('platform.dashboard.title')" :subtitle="__('platform.dashboard.subtitle')">
        <x-slot:actions>
            @if (auth('platform')->user()->isSuperAdmin())
                <x-ui.button :href="platform_route('companies.create')" icon="plus">{{ __('platform.companies.new') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <a href="{{ platform_route('companies.index') }}" class="card block p-5 transition hover:-translate-y-0.5">
            <p class="text-xs font-semibold text-muted">{{ __('platform.dashboard.total') }}</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $total }}</p>
        </a>
        @foreach (\App\Enums\TenantStatus::cases() as $status)
            <a href="{{ platform_route('companies.index', ['status' => $status->value]) }}" class="card block p-5 transition hover:-translate-y-0.5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold text-muted">{{ $status->label() }}</p>
                    <x-ui.badge :tone="$status->tone()">&nbsp;</x-ui.badge>
                </div>
                <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $byStatus[$status->value] }}</p>
            </a>
        @endforeach
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card padding="p-0">
                <div class="flex items-center justify-between border-b border-line px-5 py-4">
                    <h2 class="font-semibold">{{ __('platform.dashboard.problems') }}</h2>
                    <x-ui.badge :tone="$problems->isEmpty() ? 'success' : 'warning'">{{ $problems->count() }}</x-ui.badge>
                </div>
                @if ($problems->isEmpty())
                    <x-ui.empty-state :title="__('platform.dashboard.no_problems')" icon="check" class="py-8" />
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($problems as $company)
                            <li>
                                <a href="{{ platform_route('companies.show', $company) }}" class="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-soft">
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold">{{ $company->name }}</p>
                                        <p class="truncate text-xs text-muted">{{ $company->host() }}</p>
                                    </div>
                                    <div class="flex shrink-0 gap-1.5">
                                        @if ($company->status !== \App\Enums\TenantStatus::Active)<x-ui.badge :tone="$company->status->tone()">{{ $company->status->label() }}</x-ui.badge>@endif
                                        @if ($company->isSubscriptionExpired())<x-ui.badge tone="danger">{{ __('platform.companies.subscription_expired') }}</x-ui.badge>@endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="border-t border-line px-5 py-3 text-xs text-muted">{{ __('platform.dashboard.problems_note') }}</p>
            </x-ui.card>

            <x-ui.card padding="p-0">
                <div class="flex items-center justify-between border-b border-line px-5 py-4">
                    <h2 class="font-semibold">{{ __('platform.dashboard.events') }}</h2>
                    <a href="{{ platform_route('audit') }}" class="text-xs font-semibold text-brand-700 hover:underline">{{ __('platform.dashboard.all_events') }}</a>
                </div>
                @include('platform._events', ['events' => $events])
            </x-ui.card>
        </div>

        <div class="space-y-5">
            <x-ui.card>
                <p class="text-xs font-semibold text-muted">{{ __('platform.dashboard.new_this_month') }}</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $newThisMonth }}</p>
            </x-ui.card>

            <x-ui.card class="{{ $killSwitch['active'] ? 'ring-2 ring-amber-400' : '' }}">
                <div class="flex items-start gap-3">
                    <span @class(['grid size-10 shrink-0 place-items-center rounded-full', 'bg-amber-100 text-amber-700' => $killSwitch['active'], 'bg-brand-50 text-brand-600' => ! $killSwitch['active']])><x-ui.icon name="power" class="size-5" /></span>
                    <div>
                        <p class="font-semibold">{{ __('platform.kill_switch.title') }}</p>
                        <p class="text-sm text-muted">{{ $killSwitch['active'] ? __('platform.kill_switch.state_on') : __('platform.kill_switch.state_off') }}</p>
                        @if (auth('platform')->user()->isSuperAdmin())
                            <a href="{{ platform_route('kill-switch') }}" class="mt-2 inline-block text-xs font-semibold text-brand-700 hover:underline">{{ __('platform.dashboard.manage') }}</a>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</x-layouts.platform>
