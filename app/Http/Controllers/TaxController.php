<?php

namespace App\Http\Controllers;

use App\Enums\Actor;
use App\Enums\Permission;
use App\Enums\TaxReportType;
use App\Http\Requests\ResolveSubmissionRequest;
use App\Models\ReportApproval;
use App\Models\SignatureLog;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;
use App\Services\CurrentCompany;
use App\Submission\Gateway\TaxAuthorityGateway;
use App\Submission\ReportPayload;
use App\Submission\SubmissionOrchestrator;
use App\Submission\SubmissionRefused;
use App\Submission\SubmissionVerifier;
use App\Tax\MissingTaxRule;
use App\Tax\TaxObligations;
use App\Tax\TaxPeriod;
use App\Tax\TaxReportPreparer;
use App\Tax\TaxRuleBook;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tax calendar, reports and the approval/submission actions (owner and chief accountant). */
class TaxController extends Controller
{
    public function index(CurrentCompany $company, TaxObligations $obligations, TaxRuleBook $rules): View
    {
        $this->authorize('view-reports');

        $settings = $company->settings();

        return view('tax.index', [
            'settings' => $settings,
            'calendar' => $rules->isLoaded() ? $obligations->calendar($settings) : [],
            'reports' => TaxReport::query()->latest('period_start')->latest('id')->limit(30)->get(),
            'rulesLoaded' => $rules->isLoaded(),
            'rulesVerified' => $rules->isLoaded() && $rules->allVerified(),
        ]);
    }

    public function prepare(Request $request, TaxReportPreparer $preparer, TaxRuleBook $rules): RedirectResponse
    {
        $this->authorize('view-reports');

        $data = $request->validate([
            'type' => ['required', Rule::enum(TaxReportType::class)],
            'period_start' => ['required', 'date_format:Y-m-d'],
        ]);

        $type = TaxReportType::from($data['type']);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['period_start']);
        $deadline = $rules->deadline($type, $start);

        if ($deadline === null) {
            return back()->with('error', __('tax.errors.no_deadline'));
        }

        try {
            $report = $preparer->prepare($type, TaxPeriod::containing($deadline->period, $start), Actor::User, $request->user()->name);
        } catch (MissingTaxRule $e) {
            return back()->with('error', __('tax.errors.missing_rule', ['code' => $e->ruleCode]));
        } catch (SubmissionRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.reports.show', $report)->with('status', __('tax.prepared'));
    }

    public function show(TaxReport $report, CurrentCompany $company, TaxAuthorityGateway $gateway): View
    {
        $this->authorize('view-reports');

        return view('tax.show', [
            'report' => $report,
            'attempts' => SubmissionAttempt::query()->where('tax_report_id', $report->id)->latest('id')->limit(10)->get(),
            'approvals' => ReportApproval::query()->where('tax_report_id', $report->id)->latest('id')->limit(10)->get(),
            'signature' => $report->signature_log_id ? SignatureLog::query()->with('key')->find($report->signature_log_id) : null,
            'documentHash' => ReportPayload::hash($report, $company->settings()),
            'settings' => $company->settings(),
            'gatewayName' => $gateway->name(),
        ]);
    }

    public function recalculate(Request $request, TaxReport $report, TaxReportPreparer $preparer): RedirectResponse
    {
        $this->authorize('view-reports');

        return $this->attempt(fn () => $preparer->prepare($report->type, $report->period(), Actor::User, $request->user()->name), 'tax.recalculated');
    }

    public function requestSubmission(Request $request, TaxReport $report, SubmissionOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('view-reports');

        return $this->attempt(fn () => $orchestrator->requestSubmission($report, Actor::User, $request->user()->name), 'tax.requested');
    }

    public function approve(Request $request, TaxReport $report, SubmissionOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);

        return $this->attempt(fn () => $orchestrator->approveByUser($report, $request->user(), $this->reviewedVersion($request), $this->approvalMeta($request)), 'tax.approved');
    }

    public function cancel(Request $request, TaxReport $report, SubmissionOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);

        return $this->attempt(fn () => $orchestrator->cancel($report, $request->user()), 'tax.cancelled');
    }

    public function submitNow(Request $request, TaxReport $report, SubmissionOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize(Permission::ReportsSubmit->value);

        return $this->attempt(fn () => $orchestrator->submitNow($report, $request->user()), 'tax.submit_finished');
    }

    /** "Submit?" - "Yes": approve this version and submit it now (needs reports.approve and reports.submit). */
    public function approveAndSubmit(Request $request, TaxReport $report, SubmissionOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);
        $this->authorize(Permission::ReportsSubmit->value);

        return $this->attempt(fn () => $orchestrator->approveAndSubmit($report, $request->user(), $this->reviewedVersion($request), $this->approvalMeta($request)), 'tax.submit_finished');
    }

    /** Asks the gateway what the authority holds for a delivery whose outcome is open. Never sends anything. */
    public function verify(TaxReport $report, SubmissionVerifier $verifier): RedirectResponse
    {
        $this->authorize(Permission::ReportsSubmit->value);

        return $this->attempt(fn () => $verifier->verify($report, Actor::User, auth()->user()->name), 'tax.verify_done');
    }

    /** A person settles an open delivery after looking in the tax cabinet; the note goes to the audit log. */
    public function resolve(ResolveSubmissionRequest $request, TaxReport $report, SubmissionVerifier $verifier): RedirectResponse
    {
        $data = $request->validated();

        return $this->attempt(fn () => $verifier->resolveManually($report, $request->user(), $data['outcome'], $data['receipt'] ?? null, $data['note']), 'tax.resolve_done');
    }

    /** The version hash the page showed when the person clicked; the approval is refused if the report changed since. */
    private function reviewedVersion(Request $request): ?string
    {
        $version = $request->input('version');

        return is_string($version) && preg_match('/^[a-f0-9]{64}$/', $version) ? $version : null;
    }

    /** @return array{ip: ?string, user_agent: ?string} */
    private function approvalMeta(Request $request): array
    {
        return ['ip' => $request->ip(), 'user_agent' => $request->userAgent()];
    }

    /** DeviceSigner: the PKCS#7 the owner made with E-IMZO for the document hash shown on the page. */
    public function deviceSignature(Request $request, TaxReport $report, SubmissionOrchestrator $orchestrator): RedirectResponse
    {
        $data = $request->validate(['signature' => ['required', 'string', 'min:32', 'max:200000', 'regex:/^[A-Za-z0-9+\/=\s]+$/']], [
            'signature.regex' => __('tax.errors.signature_format'),
        ], ['signature' => __('tax.device.signature')]);

        return $this->attempt(fn () => $orchestrator->completeDeviceSignature($report, $request->user(), $data['signature']), 'tax.submit_finished');
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $report = $action();
        } catch (SubmissionRefused $e) {
            return back()->with('error', $e->getMessage());
        } catch (MissingTaxRule $e) {
            return back()->with('error', __('tax.errors.missing_rule', ['code' => $e->ruleCode]));
        }

        return redirect()->route('tax.reports.show', $report)->with('status', __($message, ['status' => $report->status->label()]));
    }
}
