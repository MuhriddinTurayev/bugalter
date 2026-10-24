<?php

namespace App\Http\Controllers;

use App\Agent\AgentTasks;
use App\Enums\Actor;
use App\Enums\Permission;
use App\Enums\TaxReportStatus;
use App\Jobs\CheckTaxPortal;
use App\Models\PortalCheck;
use App\Models\SigningKey;
use App\Models\TaxReport;
use App\Portal\TaxPortal;
use App\Services\CurrentCompany;
use App\Submission\Gateway\DeliversThroughAgent;
use App\Submission\Gateway\TaxAuthorityGateway;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The tax cabinet page: what the last visit found, what waits for approval, and "check now". */
class TaxPortalController extends Controller
{
    public function index(CurrentCompany $company, TaxPortal $portal, TaxAuthorityGateway $gateway): View
    {
        $this->authorize('view-reports');

        $latest = PortalCheck::query()->latest('id')->first();

        return view('tax.portal', [
            'settings' => $company->settings(),
            'latest' => $latest,
            'check' => $latest?->status === 'completed' ? $latest : PortalCheck::latestCompleted(),
            'history' => PortalCheck::query()->latest('id')->limit(8)->get(),
            'waiting' => TaxReport::query()
                ->whereIn('status', [TaxReportStatus::PendingApproval, TaxReportStatus::Prepared, TaxReportStatus::Blocked, TaxReportStatus::Rejected])
                ->whereDate('period_end', '<', today())
                ->orderBy('due_date')
                ->get(),
            'configErrors' => $portal->configurationErrors(),
            'portalName' => $portal->name(),
            'gatewayName' => $gateway->name(),
            'hasKey' => SigningKey::usable() !== null,
        ]);
    }

    public function check(Request $request, CurrentCompany $company, TaxPortal $portal, TaxAuthorityGateway $gateway): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);

        $settings = $company->settings();

        if (! $settings->portal_access) {
            return back()->with('error', __('tax.portal.errors.no_access'));
        }

        // The cabinet is read by the desktop agent in the accountant's browser.
        if ($gateway instanceof DeliversThroughAgent) {
            app(AgentTasks::class)->requestPortalCheck(Actor::User, $request->user()->name);

            return redirect()->route('tax.agent')->with('status', __('tax.agent.check_queued'));
        }

        if (($errors = $portal->configurationErrors()) !== []) {
            return back()->with('error', implode(' ', $errors));
        }

        if (SigningKey::usable() === null) {
            return back()->with('error', __('tax.portal.errors.no_key'));
        }

        // One visit at a time; a visit stuck for more than 15 minutes no longer blocks a new one.
        if (PortalCheck::query()->where('status', 'running')->where('started_at', '>', now()->subMinutes(15))->exists()) {
            return back()->with('error', __('tax.portal.errors.already_running'));
        }

        CheckTaxPortal::dispatch(Actor::User, $request->user()->name);

        return redirect()->route('tax.portal')->with('status', __('tax.portal.check_started'));
    }
}
