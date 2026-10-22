<?php

namespace App\Services\Platform;

use App\Models\PlatformAuditLog;
use App\Models\PlatformStaff;
use Illuminate\Support\Facades\Auth;

class PlatformAudit
{
    /** @param  array<string, mixed>  $details */
    public function record(
        string $action,
        ?string $tenantId = null,
        ?string $reason = null,
        array $details = [],
        ?PlatformStaff $staff = null,
    ): PlatformAuditLog {
        /** @var PlatformStaff|null $staff */
        $staff ??= Auth::guard('platform')->user();

        return PlatformAuditLog::create([
            'staff_id' => $staff?->id,
            'staff_name' => $staff?->name,
            'action' => $action,
            'tenant_id' => $tenantId,
            'reason' => $reason,
            'details' => $details === [] ? null : $details,
            'ip' => request()->ip(),
        ]);
    }
}
