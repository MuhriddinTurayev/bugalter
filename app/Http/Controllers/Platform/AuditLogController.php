<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\PlatformStaff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $logs = PlatformAuditLog::query()
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], (string) $request->query('action')).'%'))
            ->when($request->integer('staff_id'), fn ($q, $id) => $q->where('staff_id', $id))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', (string) $request->query('tenant_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->query('from').' 00:00:00'))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->query('to').' 23:59:59'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('platform.audit', [
            'logs' => $logs,
            'staff' => PlatformStaff::query()->orderBy('name')->get(['id', 'name']),
            'actions' => PlatformAuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
