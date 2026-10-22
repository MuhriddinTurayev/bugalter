@php $me = auth('platform')->user(); @endphp

<x-layouts.platform :title="__('platform.nav.staff')">
    <x-ui.page-header :title="__('platform.staff.title')" :subtitle="__('platform.staff.subtitle')" />

    @if (session('invitation_url'))
        <x-ui.card class="mb-5 ring-2 ring-brand-300">
            <p class="label">{{ __('platform.companies.invitation_link') }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <span class="max-w-full break-all rounded-xl bg-soft px-3 py-1.5 font-mono text-xs">{{ session('invitation_url') }}</span>
                <x-ui.copy :value="session('invitation_url')" />
            </div>
        </x-ui.card>
    @endif
    @error('staff')<div class="mb-5 rounded-2xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700 ring-1 ring-red-200">{{ $message }}</div>@enderror

    <div class="grid gap-5 lg:grid-cols-3">
        <x-ui.card padding="p-0" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[38rem]">
                    <thead><tr class="border-b border-line">
                        <th class="th">{{ __('platform.staff.member') }}</th>
                        <th class="th">{{ __('platform.staff.role') }}</th>
                        <th class="th">{{ __('platform.staff.two_factor') }}</th>
                        <th class="th">{{ __('platform.companies.fields.status') }}</th>
                        <th class="th text-right">{{ __('platform.common.actions') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($staff as $member)
                            <tr>
                                <td class="td"><div class="flex items-center gap-3"><x-ui.avatar :text="$member->initials()" tone="dark" /><div class="min-w-0"><p class="truncate font-semibold">{{ $member->name }}@if ($member->is($me)) <span class="font-normal text-muted">({{ __('platform.staff.you') }})</span>@endif</p><p class="truncate text-xs text-muted">{{ $member->email }}</p></div></div></td>
                                <td class="td">
                                    @if ($member->is($me))
                                        <x-ui.badge>{{ $member->role->label() }}</x-ui.badge>
                                    @else
                                        <form method="post" action="{{ platform_route('staff.role', ['staff' => $member->id]) }}" class="flex items-center gap-1.5">@csrf @method('PATCH')
                                            <select name="role" class="field !w-auto !py-1.5 !text-xs" aria-label="{{ __('platform.staff.role') }}" onchange="this.form.submit()">
                                                @foreach (\App\Enums\PlatformRole::cases() as $role)<option value="{{ $role->value }}" @selected($member->role === $role)>{{ $role->label() }}</option>@endforeach
                                            </select>
                                        </form>
                                    @endif
                                </td>
                                <td class="td">@if ($member->hasTwoFactor())<x-ui.badge tone="success">{{ __('platform.staff.two_factor_on') }}</x-ui.badge>@elseif ($member->password === null)<x-ui.badge tone="warning">{{ __('platform.staff.invited_badge') }}</x-ui.badge>@else<x-ui.badge tone="danger">{{ __('platform.staff.two_factor_off') }}</x-ui.badge>@endif</td>
                                <td class="td"><x-ui.badge :tone="$member->is_active ? 'success' : 'neutral'">{{ $member->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</x-ui.badge></td>
                                <td class="td text-right">
                                    @unless ($member->is($me))
                                        <form method="post" action="{{ platform_route('staff.toggle', ['staff' => $member->id]) }}">@csrf @method('PATCH')
                                            <x-ui.button type="submit" variant="ghost" size="sm" icon="power">{{ $member->is_active ? __('platform.staff.disable') : __('platform.staff.enable') }}</x-ui.button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card class="self-start">
            <h2 class="mb-4 font-semibold">{{ __('platform.staff.invite') }}</h2>
            <form method="post" action="{{ platform_route('staff.store') }}" class="space-y-4">
                @csrf
                <x-ui.input name="name" :label="__('platform.fields.name')" required />
                <x-ui.input name="email" type="email" :label="__('platform.fields.email')" required />
                <x-ui.select name="role" :label="__('platform.staff.role')" required>
                    @foreach (\App\Enums\PlatformRole::cases() as $role)<option value="{{ $role->value }}" @selected(old('role', 'support') === $role->value)>{{ $role->label() }}</option>@endforeach
                </x-ui.select>
                <x-ui.button type="submit" variant="dark" class="w-full" icon="plus">{{ __('platform.staff.send_invite') }}</x-ui.button>
            </form>
            <p class="mt-3 text-xs text-muted">{{ __('platform.staff.roles_note') }}</p>
        </x-ui.card>
    </div>
</x-layouts.platform>
