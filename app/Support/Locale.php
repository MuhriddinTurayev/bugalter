<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/** Language switcher helper: the choice lives in the session and, for signed-in users, on the user. */
final class Locale
{
    /** @return array<string, string> locale code => the language's own name */
    public static function supported(): array
    {
        return config('app.supported_locales', []);
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::supported());
    }

    public static function switchTo(Request $request, string $locale): void
    {
        abort_unless(self::isSupported($locale), 404);

        $request->session()->put('locale', $locale);

        /** @var User|null $user */
        $user = $request->user();
        $user?->forceFill(['locale' => $locale])->save();
    }
}
