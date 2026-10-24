@php
    use App\Enums\Permission;
    use App\Enums\Role;
@endphp

<x-layouts.app :title="__('ui.settings.users')">
    @include('settings._nav')

    @if (session('invitation_url'))
        <x-ui.card class="mb-5">
            <p class="label">{{ __('ui.users.invitation_link') }}</p>
            <x-ui.copy :value="session('invitation_url')" />
            <p class="mt-2 text-xs text-muted">{{ __('ui.users.invitation_link_hint') }}</p>
        </x-ui.card>
    @endif

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            @foreach ($users as $member)
                @php
                    $locked = $member->role === Role::Owner || $member->is(auth()->user());
                    $defaults = Permission::defaultsFor($member->role);
                @endphp
                <x-ui.card x-data="{ open: false }">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-ui.avatar :text="$member->initials()" tone="dark" />
                        <div class="min-w-0 grow">
                            <p class="font-semibold">{{ $member->name }}</p>
                            <p class="truncate text-xs text-muted">{{ $member->email }}@if ($member->phone) · {{ $member->phone }}@endif</p>
                        </div>
                        <x-ui.badge :tone="$member->role === Role::Owner ? 'success' : 'neutral'">{{ $member->role->label() }}</x-ui.badge>
                        @if ($member->isInvitationPending())
                            <x-ui.badge tone="warning">{{ __('ui.users.pending') }}</x-ui.badge>
                        @else
                            <x-ui.badge :tone="$member->is_active ? 'success' : 'neutral'">{{ $member->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</x-ui.badge>
                        @endif
                        @unless ($locked)
                            <x-ui.button size="sm" variant="ghost" icon="cog" @click="open = !open">{{ __('ui.users.manage') }}</x-ui.button>
                        @endunless
                    </div>

                    <p class="mt-3 text-xs text-muted">
                        {{ __('ui.users.permissions') }}:
                        {{ collect($member->permissions())->map(fn ($p) => $p->label())->implode(', ') ?: __('ui.users.no_permissions') }}
                    </p>

                    @unless ($locked)
                        <div x-cloak x-show="open" x-transition class="mt-4 grid grid-cols-1 gap-4 border-t border-line pt-4 md:grid-cols-2">
                            <form method="post" action="{{ route('settings.users.update', $member) }}" class="space-y-2">
                                @csrf @method('PATCH')
                                <x-ui.select name="role" :label="__('ui.users.fields.role')" :value="$member->role->value">
                                    @foreach ([Role::ChiefAccountant, Role::Cashier] as $role)
                                        <option value="{{ $role->value }}" @selected($member->role === $role)>{{ $role->label() }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.button type="submit" size="sm">{{ __('ui.common.save') }}</x-ui.button>
                            </form>

                            <form method="post" action="{{ route('settings.users.permissions', $member) }}" class="space-y-2">
                                @csrf @method('PUT')
                                <p class="label">{{ __('ui.users.extra_permissions') }}</p>
                                @foreach (Permission::cases() as $permission)
                                    @php $isDefault = in_array($permission, $defaults, true); @endphp
                                    <label class="flex items-center gap-2 text-sm {{ $isDefault ? 'text-muted' : '' }}">
                                        <input type="checkbox" name="permissions[]" value="{{ $permission->value }}" class="size-4 rounded border-line text-brand-600"
                                               @checked($member->hasPermission($permission)) @disabled($isDefault)>
                                        {{ $permission->label() }}@if ($isDefault) <span class="text-xs">({{ __('ui.users.by_role') }})</span>@endif
                                    </label>
                                @endforeach
                                <x-ui.button type="submit" size="sm" variant="soft">{{ __('ui.users.save_permissions') }}</x-ui.button>
                            </form>

                            <div class="flex flex-wrap gap-2 md:col-span-2">
                                @if ($member->isInvitationPending())
                                    <form method="post" action="{{ route('settings.users.resend', $member) }}">@csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary" icon="send">{{ __('ui.users.resend') }}</x-ui.button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('settings.users.toggle', $member) }}">@csrf @method('PATCH')
                                    <x-ui.confirm-button :message="$member->is_active ? __('ui.users.confirm_deactivate') : __('ui.users.confirm_activate')" variant="secondary">
                                        {{ $member->is_active ? __('ui.users.deactivate') : __('ui.users.activate') }}
                                    </x-ui.confirm-button>
                                </form>
                            </div>
                        </div>
                    @endunless
                </x-ui.card>
            @endforeach
        </div>

        <div class="space-y-5">
            <x-ui.card>
                <h2 class="mb-1 font-semibold">{{ __('ui.users.invite') }}</h2>
                <p class="mb-4 text-xs text-muted">
                    {{ $usersLimit === null ? __('ui.users.unlimited', ['used' => $usersUsed]) : __('ui.users.limit', ['used' => $usersUsed, 'limit' => $usersLimit]) }}
                </p>
                <form method="post" action="{{ route('settings.users.store') }}" class="space-y-3">
                    @csrf
                    <x-ui.input name="name" :label="__('ui.users.fields.name')" required />
                    <x-ui.input name="email" type="email" :label="__('ui.users.fields.email')" required />
                    <x-ui.input name="phone" type="tel" :label="__('ui.users.fields.phone')" placeholder="+998" />
                    <x-ui.select name="role" :label="__('ui.users.fields.role')" required>
                        <option value="{{ Role::ChiefAccountant->value }}">{{ Role::ChiefAccountant->label() }}</option>
                        <option value="{{ Role::Cashier->value }}">{{ Role::Cashier->label() }}</option>
                    </x-ui.select>
                    <x-ui.button type="submit" class="w-full" icon="send">{{ __('ui.users.send_invitation') }}</x-ui.button>
                </form>
            </x-ui.card>
            <p class="text-xs text-muted">{{ __('ui.users.note') }}</p>
        </div>
    </div>
</x-layouts.app>
