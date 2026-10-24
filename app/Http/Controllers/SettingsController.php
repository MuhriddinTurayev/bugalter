<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanySettingRequest;
use App\Models\AuditLog;
use App\Services\Audit;
use App\Services\CurrentCompany;
use App\Services\PlanLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function company(CurrentCompany $company, PlanLimits $limits): View
    {
        $this->authorize('manage-settings');

        return view('settings.company', [
            'company' => $company->settings(),
            'plan' => $limits->plan(),
            'usage' => $limits->usage(),
            'subscriptionEndsAt' => $limits->tenant()?->subscription_ends_at,
        ]);
    }

    public function updateCompany(CompanySettingRequest $request, CurrentCompany $company): RedirectResponse
    {
        $settings = $company->settings();
        $settings->fill($request->companyData())->save();
        app(Audit::class)->record('settings.company_updated', ['changed' => implode(', ', array_keys($settings->getChanges()))]);

        return redirect()->route('settings.company')->with('status', __('ui.settings.saved'));
    }

    /** The company's own audit log; it includes every platform support access and every AI/autopilot action. */
    public function audit(Request $request): View
    {
        $this->authorize('manage-users');

        $actor = in_array($request->query('actor'), ['user', 'ai', 'autopilot', 'system', 'platform'], true) ? $request->query('actor') : null;

        return view('settings.audit', [
            'logs' => AuditLog::query()->when($actor, fn ($q) => $q->where('actor_type', $actor))->latest('id')->paginate(25)->withQueryString(),
        ]);
    }
}
