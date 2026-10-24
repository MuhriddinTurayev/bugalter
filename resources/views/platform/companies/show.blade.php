@php
    use App\Enums\TenantStatus;
    use App\Services\Platform\CompanyDatabaseSize;

    $staff = auth('platform')->user();
    $isSuper = $staff->isSuperAdmin();
@endphp

<x-layouts.platform :title="$company->name">
    <x-ui.page-header :title="$company->name" :subtitle="$company->host()">
        <x-slot:actions>
            <x-ui.badge :tone="$company->status->tone()" class="!px-3.5 !py-1.5 !text-sm">{{ $company->status->label() }}</x-ui.badge>
            <x-ui.button :href="platform_route('companies.index')" variant="secondary">{{ __('platform.common.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Fresh links shown once after creating a company or re-sending an invitation --}}
    @if (session('created_url') || session('invitation_url'))
        <x-ui.card class="mb-5 ring-2 ring-brand-300">
            <h2 class="mb-3 font-semibold">{{ __('platform.companies.ready') }}</h2>
            @if (session('created_url'))
                <div class="mb-3">
                    <p class="label">{{ __('platform.companies.company_link') }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ session('created_url') }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 hover:underline">{{ session('created_url') }}</a>
                        <x-ui.copy :value="session('created_url')" />
                    </div>
                </div>
            @endif
            @if (session('invitation_url'))
                <div>
                    <p class="label">{{ __('platform.companies.invitation_link') }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="max-w-full break-all rounded-xl bg-soft px-3 py-1.5 font-mono text-xs">{{ session('invitation_url') }}</span>
                        <x-ui.copy :value="session('invitation_url')" />
                    </div>
                    <p class="mt-2 text-xs text-muted">{{ __('platform.companies.invitation_note', ['email' => $company->owner_email, 'days' => config('platform.invitation_days')]) }}</p>
                </div>
            @endif
        </x-ui.card>
    @endif

    @error('support')<div class="mb-5 rounded-2xl bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 ring-1 ring-amber-200">{{ $message }}</div>@enderror

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card>
                <h2 class="mb-4 font-semibold">{{ __('platform.companies.info') }}</h2>
                <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="label">{{ __('platform.companies.link') }}</dt><dd class="flex flex-wrap items-center gap-2"><a href="{{ $company->url() }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 hover:underline">{{ $company->host() }}</a><x-ui.copy :value="$company->url()" /></dd></div>
                    <div><dt class="label">{{ __('platform.companies.fields.stir') }}</dt><dd>{{ $company->stir ?? '—' }}</dd></div>
                    <div><dt class="label">{{ __('platform.companies.fields.plan_id') }}</dt><dd>{{ $company->plan?->name ?? '—' }}</dd></div>
                    <div><dt class="label">{{ __('platform.companies.created_at') }}</dt><dd class="tabular-nums">{{ $company->created_at->format('d.m.Y H:i') }}</dd></div>
                    <div><dt class="label">{{ __('platform.companies.last_activity') }}</dt><dd>{{ $company->last_activity_at?->diffForHumans() ?? '—' }}</dd></div>
                    <div><dt class="label">{{ __('platform.companies.db_size') }}</dt><dd class="tabular-nums">{{ CompanyDatabaseSize::format($size) }}</dd></div>
                    <div><dt class="label">{{ __('platform.companies.members') }}</dt><dd class="tabular-nums">{{ $meta['members'] ?? '—' }}</dd></div>
                    <div><dt class="label">{{ __('platform.companies.subscription') }}</dt><dd>{{ $company->subscription_ends_at ? $company->subscription_ends_at->format('d.m.Y') : __('platform.companies.not_set') }}@if ($company->isSubscriptionExpired()) <x-ui.badge tone="danger">{{ __('platform.companies.subscription_expired') }}</x-ui.badge>@endif</dd></div>
                </dl>
                @if ($staff->isSuperAdmin())
                    <form method="post" action="{{ platform_route('companies.subscription', $company) }}" class="mt-4 flex flex-wrap items-end gap-2">
                        @csrf @method('PATCH')
                        <x-ui.input name="subscription_ends_at" type="date" :label="__('platform.companies.subscription_until')" :value="$company->subscription_ends_at?->toDateString()" class="w-48" />
                        <x-ui.button type="submit" variant="secondary" size="sm">{{ __('platform.common.save') }}</x-ui.button>
                    </form>
                    <p class="mt-1 text-xs text-muted">{{ __('platform.companies.subscription_hint') }}</p>
                @endif
                <p class="mt-4 rounded-xl bg-soft px-3 py-2 text-xs text-muted">{{ __('platform.companies.no_business_data') }}</p>
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-4 font-semibold">{{ __('platform.companies.owner') }}</h2>
                <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="label">{{ __('platform.companies.fields.owner_name') }}</dt><dd>{{ $company->owner_name ?? '—' }}</dd></div>
                    <div><dt class="label">{{ __('platform.companies.fields.owner_email') }}</dt><dd>{{ $company->owner_email ?? '—' }}</dd></div>
                    <div><dt class="label">{{ __('platform.companies.fields.owner_phone') }}</dt><dd>{{ $company->owner_phone ?? '—' }}</dd></div>
                    <div><dt class="label">{{ __('platform.companies.invitation') }}</dt><dd>
                        @if ($meta === null) — @elseif ($meta['invitation_pending']) <x-ui.badge tone="warning">{{ __('platform.companies.invitation_pending') }}</x-ui.badge> @else <x-ui.badge tone="success">{{ __('platform.companies.invitation_accepted') }}</x-ui.badge> @endif
                        @if ($company->owner_invited_at)<span class="ml-1 text-xs text-muted">{{ $company->owner_invited_at->format('d.m.Y') }}</span>@endif
                    </dd></div>
                </dl>
                @if ($isSuper)
                    <form method="post" action="{{ platform_route('companies.invitation', $company) }}" class="mt-4">@csrf
                        <x-ui.button type="submit" variant="secondary" size="sm" icon="link">{{ __('platform.companies.resend') }}</x-ui.button>
                    </form>
                @endif
            </x-ui.card>

            @if ($recentSessions->isNotEmpty())
                <x-ui.card padding="p-0">
                    <h2 class="border-b border-line px-5 py-4 font-semibold">{{ __('platform.support.recent') }}</h2>
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($recentSessions as $s)
                            <li class="px-5 py-3">
                                <p class="font-semibold">{{ $s->staff?->name }} <span class="font-normal text-muted">· {{ $s->started_at->format('d.m.Y H:i') }}</span></p>
                                <p class="text-xs text-muted">{{ $s->reason }}</p>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-5">
            {{-- Support access --}}
            <x-ui.card x-data="{ open: {{ $errors->has('reason') ? 'true' : 'false' }} }">
                <h2 class="font-semibold">{{ __('platform.support.title') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('platform.support.description', ['minutes' => $minutes]) }}</p>

                @if ($supportSession)
                    <div class="mt-4 rounded-2xl bg-amber-50 p-3 text-sm text-amber-900">
                        <p class="font-semibold">{{ __('platform.support.active_until', ['time' => $supportSession->expires_at->format('H:i')]) }}</p>
                    </div>
                    <x-ui.button :href="platform_route('support.show', $company)" variant="dark" class="mt-3 w-full" icon="eye">{{ __('platform.support.open') }}</x-ui.button>
                    <form method="post" action="{{ platform_route('support.end', ['session' => $supportSession->id]) }}" class="mt-2">@csrf @method('DELETE')
                        <x-ui.button type="submit" variant="secondary" class="w-full">{{ __('platform.support.end') }}</x-ui.button>
                    </form>
                @else
                    <x-ui.button variant="dark" class="mt-4 w-full" icon="eye" @click="open = true">{{ __('platform.support.button') }}</x-ui.button>
                    <div x-cloak x-show="open" x-transition class="fixed inset-0 z-50 grid place-items-center bg-ink/50 p-4" @keydown.escape.window="open = false">
                        <div class="card w-full max-w-md p-6" @click.outside="open = false">
                            <h3 class="text-lg font-semibold">{{ __('platform.support.title') }}</h3>
                            <p class="mt-1 text-sm text-muted">{{ __('platform.support.modal_text', ['company' => $company->name, 'minutes' => $minutes]) }}</p>
                            <form method="post" action="{{ platform_route('support.start', $company) }}" class="mt-4 space-y-4">
                                @csrf
                                <x-ui.textarea name="reason" :label="__('platform.support.reason')" rows="4" required minlength="{{ config('platform.support_reason_min_length') }}" :placeholder="__('platform.support.reason_placeholder')" />
                                <p class="text-xs text-muted">{{ __('platform.support.audit_note') }}</p>
                                <div class="flex justify-end gap-2">
                                    <x-ui.button variant="ghost" @click="open = false">{{ __('platform.common.cancel') }}</x-ui.button>
                                    <x-ui.button type="submit" variant="dark">{{ __('platform.support.start') }}</x-ui.button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
            </x-ui.card>

            @if ($isSuper)
                <x-ui.card>
                    <h2 class="mb-3 font-semibold">{{ __('platform.companies.plan_section') }}</h2>
                    <form method="post" action="{{ platform_route('companies.plan', $company) }}" class="space-y-3">
                        @csrf @method('PATCH')
                        <select name="plan_id" class="field" aria-label="{{ __('platform.companies.fields.plan_id') }}">
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->id }}" @selected($company->plan_id === $plan->id)>{{ $plan->name }} — {{ $plan->priceLabel() }}</option>
                            @endforeach
                        </select>
                        <x-ui.button type="submit" variant="secondary" class="w-full">{{ __('platform.companies.change_plan') }}</x-ui.button>
                    </form>
                </x-ui.card>

                <x-ui.card x-data="{ open: false }">
                    <h2 class="mb-3 font-semibold">{{ __('platform.companies.access') }}</h2>
                    @if ($company->status === TenantStatus::Suspended)
                        <form method="post" action="{{ platform_route('companies.restore', $company) }}">@csrf
                            <x-ui.button type="submit" class="w-full" icon="check">{{ __('platform.companies.restore') }}</x-ui.button>
                        </form>
                    @elseif ($company->status === TenantStatus::Active)
                        <x-ui.button variant="secondary" class="w-full !text-red-600" icon="pause" @click="open = !open">{{ __('platform.companies.suspend') }}</x-ui.button>
                        <form x-cloak x-show="open" method="post" action="{{ platform_route('companies.suspend', $company) }}" class="mt-3 space-y-3">
                            @csrf
                            <x-ui.textarea name="reason" :label="__('platform.companies.suspend_reason')" rows="2" required minlength="5" />
                            <x-ui.button type="submit" variant="danger" class="w-full">{{ __('platform.companies.suspend_confirm') }}</x-ui.button>
                        </form>
                    @endif
                    <p class="mt-3 text-xs text-muted">{{ __('platform.companies.suspend_note') }}</p>
                </x-ui.card>
            @endif
        </div>
    </div>
</x-layouts.platform>
