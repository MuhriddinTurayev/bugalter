<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/** The company owner sets a password from the link in the invitation email. */
class InvitationController extends Controller
{
    public function show(string $token, CurrentCompany $company): View
    {
        return view('auth.invitation', ['user' => $this->find($token), 'token' => $token, 'company' => $company->name()]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $this->find($token);

        $data = $request->validate(
            ['password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()]],
            [],
            ['password' => __('ui.login.password')],
        );

        $user->forceFill([
            'password' => $data['password'],
            'invitation_token_hash' => null,
            'invitation_expires_at' => null,
            'email_verified_at' => now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function find(string $token): User
    {
        $user = User::query()
            ->where('invitation_token_hash', hash('sha256', $token))
            ->where('invitation_expires_at', '>', now())
            ->where('is_active', true)
            ->first();

        abort_if($user === null, 404);

        return $user;
    }
}
