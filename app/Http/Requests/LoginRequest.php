<?php

namespace App\Http\Requests;

use App\Models\User;
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
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return ['login' => __('ui.login.login')];
    }

    /** @throws ValidationException */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = $this->findUser();

        if ($user === null || ! $user->is_active || ! Auth::attempt(
            ['id' => $user->id, 'password' => $this->input('password')],
            $this->boolean('remember'),
        )) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /** Accepts an email address or a phone number in any common format. */
    private function findUser(): ?User
    {
        $login = trim((string) $this->input('login'));

        if (str_contains($login, '@')) {
            return User::query()->where('email', Str::lower($login))->first();
        }

        $phone = self::normalizePhone($login);

        return $phone === null ? null : User::query()->where('phone', $phone)->first();
    }

    public static function normalizePhone(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (strlen($digits) === 9) {
            $digits = '998'.$digits;
        }

        return strlen($digits) === 12 && str_starts_with($digits, '998') ? '+'.$digits : null;
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'login' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($this->throttleKey())]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('login')).'|'.$this->ip());
    }
}
