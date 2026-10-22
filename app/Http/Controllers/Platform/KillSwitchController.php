<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\KillSwitch;
use App\Services\Platform\PlatformAudit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class KillSwitchController extends Controller
{
    public function show(): View
    {
        return view('platform.kill-switch', ['state' => KillSwitch::state()]);
    }

    public function update(Request $request, PlatformAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [], ['reason' => __('platform.support.reason')]);

        $active = (bool) $data['active'];
        $staff = $request->user('platform');

        KillSwitch::set($active, $data['reason'], $staff->name);
        $audit->record($active ? 'killswitch.enabled' : 'killswitch.disabled', null, $data['reason'], [], $staff);

        return back()->with('status', __($active ? 'platform.kill_switch.enabled' : 'platform.kill_switch.disabled'));
    }
}
