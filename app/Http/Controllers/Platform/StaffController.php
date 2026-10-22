<?php

namespace App\Http\Controllers\Platform;

use App\Enums\PlatformRole;
use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Notifications\StaffInvitation;
use App\Services\Platform\PlatformAudit;
use App\Support\PlatformUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class StaffController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function index(): View
    {
        return view('platform.staff.index', ['staff' => PlatformStaff::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('platform_staff', 'email')],
            'role' => ['required', Rule::enum(PlatformRole::class)],
        ], [], ['name' => __('platform.fields.name'), 'email' => __('platform.fields.email'), 'role' => __('platform.staff.role')]);

        $token = Str::random(48);

        $staff = new PlatformStaff(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role']]);
        $staff->forceFill([
            'is_active' => true,
            'invitation_token_hash' => hash('sha256', $token),
            'invitation_expires_at' => now()->addDays(config('platform.invitation_days')),
        ])->save();

        $url = PlatformUrl::route('invitation.show', ['token' => $token]);

        try {
            Notification::route('mail', $staff->email)->notify(new StaffInvitation($staff, $url));
        } catch (Throwable $e) {
            report($e); // the link is also shown below, so a mail failure is not fatal
        }

        $this->audit->record('staff.invited', null, null, ['email' => $staff->email, 'role' => $staff->role->value], $request->user('platform'));

        return back()->with('status', __('platform.staff.invited'))->with('invitation_url', $url);
    }

    public function toggle(Request $request, PlatformStaff $staff): RedirectResponse
    {
        /** @var PlatformStaff $me */
        $me = $request->user('platform');

        if ($staff->is($me)) {
            return back()->withErrors(['staff' => __('platform.staff.cannot_self')]);
        }

        if ($staff->is_active && $this->isLastActiveSuperAdmin($staff)) {
            return back()->withErrors(['staff' => __('platform.staff.last_super_admin')]);
        }

        $staff->update(['is_active' => ! $staff->is_active]);
        $this->audit->record($staff->is_active ? 'staff.enabled' : 'staff.disabled', null, null, ['email' => $staff->email], $me);

        return back()->with('status', __('platform.staff.updated'));
    }

    public function changeRole(Request $request, PlatformStaff $staff): RedirectResponse
    {
        /** @var PlatformStaff $me */
        $me = $request->user('platform');
        $data = $request->validate(['role' => ['required', Rule::enum(PlatformRole::class)]]);

        if ($staff->isSuperAdmin() && $data['role'] !== PlatformRole::SuperAdmin->value && $this->isLastActiveSuperAdmin($staff)) {
            return back()->withErrors(['staff' => __('platform.staff.last_super_admin')]);
        }

        $before = $staff->role->value;
        $staff->update(['role' => $data['role']]);
        $this->audit->record('staff.role_changed', null, null, ['email' => $staff->email, 'from' => $before, 'to' => $data['role']], $me);

        return back()->with('status', __('platform.staff.updated'));
    }

    private function isLastActiveSuperAdmin(PlatformStaff $staff): bool
    {
        return $staff->isSuperAdmin()
            && PlatformStaff::query()->where('role', PlatformRole::SuperAdmin->value)->where('is_active', true)->whereKeyNot($staff->id)->doesntExist();
    }
}
