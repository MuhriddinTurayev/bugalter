@php use App\Services\Platform\CompanyDatabaseSize; @endphp

<x-layouts.platform :title="__('platform.nav.companies')">
    <x-ui.page-header :title="__('platform.companies.title')" :subtitle="__('platform.companies.subtitle')">
        <x-slot:actions>
            @if (auth('platform')->user()->isSuperAdmin())
                <x-ui.button :href="platform_route('companies.create')" icon="plus">{{ __('platform.companies.new') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card padding="p-0">
        <form method="get" class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[1fr_11rem_11rem_auto]">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('platform.companies.search') }}" class="field" aria-label="{{ __('platform.common.search') }}">
            <select name="status" class="field" aria-label="{{ __('platform.companies.fields.status') }}">
                <option value="">{{ __('platform.companies.all_statuses') }}</option>
                @foreach (\App\Enums\TenantStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
            <select name="plan_id" class="field" aria-label="{{ __('platform.companies.fields.plan_id') }}">
                <option value="">{{ __('platform.companies.all_plans') }}</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}" @selected((int) request('plan_id') === $plan->id)>{{ $plan->name }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <x-ui.button type="submit" variant="dark">{{ __('platform.common.filter') }}</x-ui.button>
                @if (request()->hasAny(['q', 'status', 'plan_id', 'problem']))
                    <x-ui.button :href="platform_route('companies.index')" variant="ghost">{{ __('platform.common.reset') }}</x-ui.button>
                @endif
            </div>
        </form>

        @if ($companies->isEmpty())
            <x-ui.empty-state :title="__('platform.companies.empty')" :text="__('platform.companies.empty_text')" icon="building" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[58rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('platform.companies.fields.name') }}</th>
                        <th class="th">{{ __('platform.companies.link') }}</th>
                        <th class="th">{{ __('platform.companies.fields.plan_id') }}</th>
                        <th class="th">{{ __('platform.companies.fields.status') }}</th>
                        <th class="th">{{ __('platform.companies.created_at') }}</th>
                        <th class="th">{{ __('platform.companies.last_activity') }}</th>
                        <th class="th text-right">{{ __('platform.companies.db_size') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($companies as $company)
                            <tr class="transition hover:bg-soft/60">
                                <td class="td">
                                    <a href="{{ platform_route('companies.show', $company) }}" class="font-semibold hover:text-brand-700">{{ $company->name }}</a>
                                    <p class="text-xs text-muted">{{ __('ui.contractors.fields.stir') }}: {{ $company->stir ?? '—' }}</p>
                                </td>
                                <td class="td">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ $company->url() }}" target="_blank" rel="noopener" class="text-brand-700 hover:underline">{{ $company->host() }}</a>
                                        <x-ui.copy :value="$company->url()" />
                                    </div>
                                </td>
                                <td class="td">{{ $company->plan?->name ?? '—' }}</td>
                                <td class="td">
                                    <x-ui.badge :tone="$company->status->tone()">{{ $company->status->label() }}</x-ui.badge>
                                    @if ($company->isSubscriptionExpired())<x-ui.badge tone="danger" class="mt-1">{{ __('platform.companies.subscription_expired') }}</x-ui.badge>@endif
                                </td>
                                <td class="td tabular-nums">{{ $company->created_at->format('d.m.Y') }}</td>
                                <td class="td tabular-nums text-muted">{{ $company->last_activity_at?->diffForHumans() ?? '—' }}</td>
                                <td class="td amount text-right">{{ CompanyDatabaseSize::format($sizes[$company->id] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line p-4">{{ $companies->links() }}</div>
        @endif
    </x-ui.card>
</x-layouts.platform>
