<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;

/**
 * The platform-level kill switch for autopilot: when active, no company may submit anything
 * automatically. The autopilot itself is built later; its gates must call isActive() first.
 */
class KillSwitch
{
    public const KEY = 'autopilot_kill_switch';

    public static function isActive(): bool
    {
        return (bool) (PlatformSetting::read(self::KEY)['active'] ?? false);
    }

    /** @return array{active: bool, reason: ?string, by: ?string, at: ?string} */
    public static function state(): array
    {
        return array_merge(['active' => false, 'reason' => null, 'by' => null, 'at' => null], PlatformSetting::read(self::KEY, []));
    }

    public static function set(bool $active, string $reason, string $by): void
    {
        PlatformSetting::write(self::KEY, ['active' => $active, 'reason' => $reason, 'by' => $by, 'at' => now()->toIso8601String()]);
    }
}
