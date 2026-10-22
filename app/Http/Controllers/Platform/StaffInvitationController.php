<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Services\Platform\PlatformAudit;
use App\Support\PlatformUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/** A new staff member sets a password from the emailed link, then must enrol two-factor. */
class StaffInvitationController extends Controller
{
    public function show(string $token): View
    {
        return view('platform.auth.invitation', ['staff' => $this->find($token), 'token' => $token]);
    }

    public function accept(Request $request, string $token, PlatformAudit $audit): RedirectResponse
    {
        $staff = $this->find($token);

        $data = $request->validate(
            ['password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]],
            [],
            ['password' => __('platform.fields.password')],
        );

        $staff->forceFill(['password' => $data['password'], 'invitation_token_hash' => null, 'invitation_expires_at' => null])->save();
        $audit->record('staff.invitation_accepted', null, null, [], $staff);

        Auth::guard('platform')->login($staff);
        $request->session()->regenerate();

        return redirect(PlatformUrl::route('two-factor.enroll'));
    }

    private function find(string $token): PlatformStaff
    {
        $staff = PlatformStaff::query()
            ->where('invitation_token_hash', hash('sha256', $token))
            ->where('invitation_expires_at', '>', now())
            ->where('is_active', true)
            ->first();

        abort_if($staff === null, 404);

        return $staff;
    }
}
