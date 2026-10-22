<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\PlatformStaff;
use App\Models\SupportSession;
use App\Models\Tenant;

/**
 * Read-only, time-limited, reasoned and audited access for platform staff to a company's data.
 * The panel shows no company business data at all outside an active support session.
 */
class SupportAccess
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function start(PlatformStaff $staff, Tenant $tenant, string $reason): SupportSession
    {
        $this->endAllFor($staff, $tenant);

        $session = SupportSession::create([
            'staff_id' => $staff->id,
            'tenant_id' => $tenant->id,
            'reason' => $reason,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(config('platform.support_access_minutes')),
            'ip' => request()->ip(),
        ]);

        $this->audit->record('support_access.started', $tenant->id, $reason, [
            'expires_at' => $session->expires_at->toIso8601String(),
        ], $staff);

        $this->writeCompanyAudit($tenant, 'support_access.started', $staff, $reason, [
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);

        return $session;
    }

    public function end(SupportSession $session): void
    {
        if ($session->ended_at !== null) {
            return;
        }

        $session->update(['ended_at' => now()]);
        $tenant = Tenant::find($session->tenant_id);

        $this->audit->record('support_access.ended', $session->tenant_id, null, [], $session->staff);

        if ($tenant) {
            $this->writeCompanyAudit($tenant, 'support_access.ended', $session->staff, null, []);
        }
    }

    /** The staff member's current valid session for this company, if any. Expired sessions never count. */
    public function active(PlatformStaff $staff, Tenant $tenant): ?SupportSession
    {
        return SupportSession::query()
            ->where('staff_id', $staff->id)
            ->where('tenant_id', $tenant->id)
            ->whereNull('ended_at')
            ->where('expires_at', '>', now())
            ->latest('started_at')
            ->first();
    }

    /** Record that support looked at a page, on both sides. */
    public function recordView(PlatformStaff $staff, Tenant $tenant, string $page): void
    {
        $this->audit->record('support_access.viewed', $tenant->id, null, ['page' => $page], $staff);
        $this->writeCompanyAudit($tenant, 'support_access.viewed', $staff, null, ['page' => $page]);
    }

    private function endAllFor(PlatformStaff $staff, Tenant $tenant): void
    {
        SupportSession::query()
            ->where('staff_id', $staff->id)
            ->where('tenant_id', $tenant->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
    }

    /** @param  array<string, mixed>  $details */
    private function writeCompanyAudit(Tenant $tenant, string $action, PlatformStaff $staff, ?string $reason, array $details): void
    {
        $tenant->run(fn () => AuditLog::create([
            'actor_type' => 'platform',
            'actor_name' => $staff->name,
            'action' => $action,
            'reason' => $reason,
            'details' => $details === [] ? null : $details,
        ]));
    }
}
