<?php

namespace App\Services\Platform;

use App\Models\PlatformStaff;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP enrolment and verification for platform staff. Mandatory; see config/platform.php for the local-only escape hatch. */
class TwoFactor
{
    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Skipping enrolment is possible only in the local environment and only when explicitly enabled.
     * Every place that honours a skip must call this, so the rule cannot be bypassed by session data.
     */
    public static function skipAllowed(): bool
    {
        return app()->environment('local') && (bool) config('platform.two_factor_optional');
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function qrSvg(PlatformStaff $staff, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(config('platform.two_factor_issuer'), $staff->email, $secret);

        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);
    }

    /** Verifies a code once: a code that was already accepted can never be replayed. */
    public function verify(PlatformStaff $staff, string $code, ?string $secret = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // With a null baseline the library only answers `true`, not the matched time step, which
        // would silently disable replay protection for the first code. A baseline of 0 returns the step.
        $timestamp = $this->google2fa->verifyKeyNewer(
            $secret ?? (string) $staff->two_factor_secret,
            $code,
            $staff->two_factor_last_timestamp ?? 0,
            1,
        );

        if (! is_int($timestamp)) {
            return false;
        }

        $staff->forceFill(['two_factor_last_timestamp' => $timestamp])->save();

        return true;
    }

    /** @return list<string> plain codes, shown to the user once; only hashes are stored */
    public function issueRecoveryCodes(PlatformStaff $staff): array
    {
        $codes = collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();

        $staff->forceFill(['two_factor_recovery_codes' => array_map(fn (string $code) => $this->hashCode($code), $codes)])->save();

        return $codes;
    }

    /** Uses up a recovery code; returns false when it is wrong or was already used. */
    public function consumeRecoveryCode(PlatformStaff $staff, string $code): bool
    {
        $hash = $this->hashCode(Str::lower(trim($code)));
        $codes = $staff->two_factor_recovery_codes ?? [];

        if (! in_array($hash, $codes, true)) {
            return false;
        }

        $staff->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();

        return true;
    }

    public function remainingRecoveryCodes(PlatformStaff $staff): int
    {
        return count($staff->two_factor_recovery_codes ?? []);
    }

    public function confirm(PlatformStaff $staff): void
    {
        $staff->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    public function reset(PlatformStaff $staff): void
    {
        $staff->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestamp' => null,
        ])->save();
    }

    private function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }
}
