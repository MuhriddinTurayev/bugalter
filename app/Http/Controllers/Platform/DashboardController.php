<?php

namespace App\Http\Controllers\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Services\Platform\KillSwitch;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $byStatus = Tenant::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $problems = Tenant::query()
            ->with('plan')
            ->where(fn ($q) => $q
                ->whereIn('status', [TenantStatus::Suspended->value, TenantStatus::Failed->value])
                ->orWhere('subscription_ends_at', '<', now()))
            ->orderBy('name')
            ->limit(10)
            ->get();

        return view('platform.dashboard', [
            'total' => (int) $byStatus->sum(),
            'byStatus' => collect(TenantStatus::cases())->mapWithKeys(fn (TenantStatus $s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'newThisMonth' => Tenant::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            'problems' => $problems,
            'events' => PlatformAuditLog::query()->latest('id')->limit(8)->get(),
            'killSwitch' => KillSwitch::state(),
        ]);
    }
}
