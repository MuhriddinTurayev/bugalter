<?php

namespace App\Http\Requests\Platform;

use App\Models\PlatformStaff;
use App\Services\Platform\PlatformAudit;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return ['email' => __('platform.fields.email'), 'password' => __('platform.fields.password')];
    }

    /** @throws ValidationException */
    public function authenticate(): PlatformStaff
    {
        $this->ensureIsNotRateLimited();

        $email = Str::lower((string) $this->input('email'));

        if (! Auth::guard('platform')->attempt(['email' => $email, 'password' => $this->input('password'), 'is_active' => true])) {
            RateLimiter::hit($this->throttleKey());
            app(PlatformAudit::class)->record('staff.login_failed', null, null, ['email' => $email]);

            throw ValidationException::withMessages(['email' => __('platform.login.failed')]);
        }

        RateLimiter::clear($this->throttleKey());

        /** @var PlatformStaff */
        return Auth::guard('platform')->user();
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($this->throttleKey())]),
        ]);
    }

    private function throttleKey(): string
    {
        return 'platform-login|'.Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}
