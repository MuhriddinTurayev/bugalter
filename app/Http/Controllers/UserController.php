<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Notifications\UserInvitation;
use App\Services\Audit;
use App\Services\CurrentCompany;
use App\Services\PlanLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** The owner manages the company's people: invitations, roles, access and extra permissions. */
class UserController extends Controller
{
    public function __construct(private readonly Audit $audit) {}

    public function index(PlanLimits $limits): View
    {
        $this->authorize('manage-users');

        return view('settings.users', [
            'users' => User::query()->with('grantedPermissions')->orderByRaw("role = 'owner' desc")->orderBy('name')->get(),
            'usersLimit' => $limits->limit(PlanLimits::USERS),
            'usersUsed' => $limits->used(PlanLimits::USERS),
        ]);
    }

    public function store(Request $request, PlanLimits $limits, CurrentCompany $company): RedirectResponse
    {
        $this->authorize('manage-users');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')],
            'role' => ['required', Rule::in([Role::ChiefAccountant->value, Role::Cashier->value])],
        ], [], [
            'name' => __('ui.users.fields.name'),
            'email' => __('ui.users.fields.email'),
            'phone' => __('ui.users.fields.phone'),
            'role' => __('ui.users.fields.role'),
        ]);

        $limits->ensure(PlanLimits::USERS);

        $token = Str::random(48);
        $user = new User(['name' => $data['name'], 'email' => Str::lower($data['email']), 'phone' => $data['phone'] ?? null, 'role' => $data['role'], 'locale' => app()->getLocale()]);
        $user->forceFill([
            'is_active' => true,
            'invitation_token_hash' => hash('sha256', $token),
            'invitation_expires_at' => now()->addDays(config('platform.invitation_days')),
        ])->save();

        $url = url('/invitation/'.$token);
        $this->sendInvitation($user, $url, $company->name());
        $this->audit->record('user.invited', ['user' => $user->id, 'email' => $user->email, 'role' => $user->role->value]);

        return back()->with('status', __('ui.users.invited', ['email' => $user->email]))->with('invitation_url', $url);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-users');
        $this->guardOwner($user, $request->user());

        $data = $request->validate([
            'role' => ['required', Rule::in([Role::ChiefAccountant->value, Role::Cashier->value])],
        ]);

        $before = $user->role->value;
        $user->update(['role' => $data['role']]);
        $this->audit->record('user.role_changed', ['user' => $user->id, 'from' => $before, 'to' => $data['role']]);

        return back()->with('status', __('ui.users.updated', ['name' => $user->name]));
    }

    public function toggle(Request $request, User $user, PlanLimits $limits): RedirectResponse
    {
        $this->authorize('manage-users');
        $this->guardOwner($user, $request->user());

        if (! $user->is_active) {
            $limits->ensure(PlanLimits::USERS);
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        $this->audit->record($user->is_active ? 'user.activated' : 'user.deactivated', ['user' => $user->id, 'email' => $user->email]);

        return back()->with('status', __($user->is_active ? 'ui.users.activated' : 'ui.users.deactivated', ['name' => $user->name]));
    }

    public function resend(Request $request, User $user, CurrentCompany $company): RedirectResponse
    {
        $this->authorize('manage-users');
        abort_unless($user->isInvitationPending(), 422);

        $token = Str::random(48);
        $user->forceFill([
            'invitation_token_hash' => hash('sha256', $token),
            'invitation_expires_at' => now()->addDays(config('platform.invitation_days')),
        ])->save();

        $url = url('/invitation/'.$token);
        $this->sendInvitation($user, $url, $company->name());
        $this->audit->record('user.invitation_resent', ['user' => $user->id]);

        return back()->with('status', __('ui.users.invitation_resent', ['email' => $user->email]))->with('invitation_url', $url);
    }

    /** Only the owner grants permissions, and only to someone else; the AI never holds any. */
    public function permissions(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-users');
        $this->guardOwner($user, $request->user());

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => [Rule::enum(Permission::class)],
        ]);

        $wanted = collect($data['permissions'] ?? [])->unique()
            ->reject(fn (string $p) => in_array(Permission::from($p), Permission::defaultsFor($user->role), true))
            ->values();

        $user->grantedPermissions()->whereNotIn('permission', $wanted)->delete();
        foreach ($wanted as $permission) {
            UserPermission::query()->firstOrCreate(['user_id' => $user->id, 'permission' => $permission], ['granted_by' => $request->user()->id]);
        }

        $this->audit->record('user.permissions_changed', ['user' => $user->id, 'granted' => $wanted->implode(', ') ?: '—']);

        return back()->with('status', __('ui.users.permissions_saved', ['name' => $user->name]));
    }

    private function guardOwner(User $user, User $actor): void
    {
        // The owner's own role and access cannot be changed from here (that would lock the company out).
        abort_if($user->role === Role::Owner || $user->is($actor), 403);
    }

    private function sendInvitation(User $user, string $url, string $company): void
    {
        try {
            Notification::send($user, new UserInvitation($company, $url));
        } catch (Throwable $e) {
            report($e); // the link is shown on the page as well
        }
    }
}
