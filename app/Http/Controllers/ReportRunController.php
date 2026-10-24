<?php

namespace App\Http\Controllers;

use App\Enums\Actor;
use App\Enums\Permission;
use App\Enums\TaxReportStatus;
use App\Http\Requests\ApproveReportsRequest;
use App\Http\Requests\StartReportRunRequest;
use App\Jobs\RunReportWorkflow;
use App\Models\Account;
use App\Models\ReportRun;
use App\Models\SigningKey;
use App\Models\TaxReport;
use App\Portal\TaxPortal;
use App\Services\CurrentCompany;
use App\Submission\BatchApproval;
use App\Submission\Gateway\TaxAuthorityGateway;
use App\Tax\ReportWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Hisobotlarni avtomatik tayyorlash": step 1 (company and month), step 2 (live progress), step 3 (review),
 * step 4 (approval of the selected versions with a declaration) and step 5 (each report's submission status).
 */
class ReportRunController extends Controller
{
    public function create(CurrentCompany $company, TaxPortal $portal, TaxAuthorityGateway $gateway): View
    {
        $this->authorize('view-reports');

        $settings = $company->settings();

        return view('tax.runs.create', [
            'settings' => $settings,
            'month' => now()->subMonthNoOverflow()->format('Y-m'),
            'active' => ReportRun::activeRun(),
            'recent' => ReportRun::query()->latest('id')->limit(5)->get(),
            'sources' => [
                'accounts' => Account::query()->count(),
                'portal_access' => (bool) $settings->portal_access,
                'portal_errors' => $portal->configurationErrors(),
                'portal' => $portal->name(),
                'key' => SigningKey::usable(),
                'signer_mode' => $settings->signer_mode,
                'gateway' => $gateway->name(),
            ],
        ]);
    }

    public function store(StartReportRunRequest $request): RedirectResponse
    {
        if (($active = ReportRun::activeRun()) !== null) {
            return redirect()->route('tax.runs.show', $active)->with('error', __('tax.workflow.errors.already_running'));
        }

        $run = ReportRun::create([
            'status' => 'queued',
            'period_month' => $request->month()->toDateString(),
            'actor_type' => Actor::User->value,
            'actor_name' => $request->user()->name,
            'user_id' => $request->user()->id,
        ]);

        RunReportWorkflow::dispatch($run->id);

        return redirect()->route('tax.runs.show', $run);
    }

    public function show(ReportRun $run, ReportWorkflow $workflow, CurrentCompany $company, TaxAuthorityGateway $gateway): View
    {
        $this->authorize('view-reports');

        $user = auth()->user();

        return view('tax.runs.show', [
            'run' => $run,
            'rows' => $run->status === 'completed' ? $workflow->rows($run) : [],
            'settings' => $company->settings(),
            'gatewayName' => $gateway->name(),
            'canSubmit' => $user->hasPermission(Permission::ReportsApprove) && $user->hasPermission(Permission::ReportsSubmit),
            'status' => $this->statusPayload($run),
        ]);
    }

    /** Polled by the page while something is in motion: the run's steps and each report's state. */
    public function status(ReportRun $run): JsonResponse
    {
        $this->authorize('view-reports');

        return response()->json($this->statusPayload($run));
    }

    /** Step 4: the final review of the selected reports. */
    public function confirm(Request $request, ReportRun $run, ReportWorkflow $workflow, CurrentCompany $company, TaxAuthorityGateway $gateway): View|RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);
        $this->authorize(Permission::ReportsSubmit->value);

        $selected = array_map('intval', (array) $request->query('reports', []));
        $rows = array_values(array_filter($workflow->rows($run), fn (array $row) => $row['selectable'] && in_array($row['report']->id, $selected, true)));

        if ($rows === []) {
            return redirect()->route('tax.runs.show', $run)->with('error', __('tax.workflow.errors.nothing_selected'));
        }

        return view('tax.runs.confirm', [
            'run' => $run,
            'rows' => $rows,
            'settings' => $company->settings(),
            'gateway' => $gateway->name(),
            'total' => array_sum(array_map(fn (array $row) => $row['report']->payable_minor, $rows)),
        ]);
    }

    /** "Tanlangan hisobotlarni tasdiqlash va topshirish": the backend binds the approval to the reviewed versions. */
    public function approve(ApproveReportsRequest $request, ReportRun $run, BatchApproval $approval): RedirectResponse
    {
        $versions = $request->versions();
        $allowed = collect($run->results ?? [])->pluck('report_id')->filter()->map(fn ($id) => (int) $id)->all();

        if (array_diff(array_keys($versions), $allowed) !== []) {
            return redirect()->route('tax.runs.show', $run)->with('error', __('tax.workflow.errors.not_in_run'));
        }

        $result = $approval($request->user(), $versions, ['ip' => $request->ip(), 'user_agent' => $request->userAgent()]);

        if ($result['approved'] === []) {
            return redirect()->route('tax.runs.show', $run)->with('error', __('tax.workflow.errors.batch_refused', ['errors' => implode(' ', array_unique($result['refused']))]));
        }

        $message = __('tax.workflow.batch_started', ['count' => count($result['approved'])]);

        return redirect()->route('tax.runs.show', $run)->with($result['refused'] === [] ? 'status' : 'error', $result['refused'] === []
            ? $message
            : $message.' '.implode(' ', array_unique($result['refused'])));
    }

    /** @return array<string, mixed> */
    private function statusPayload(ReportRun $run): array
    {
        $ids = collect($run->results ?? [])->pluck('report_id')->filter()->all();
        $reports = $ids === [] ? collect() : TaxReport::query()->whereIn('id', $ids)->get(['id', 'status']);
        $moving = $reports->contains(fn (TaxReport $r) => in_array($r->status, [TaxReportStatus::Submitting, TaxReportStatus::Scheduled], true));

        return [
            'status' => $run->isActive() || ! in_array($run->status, ['queued', 'running'], true) ? $run->status : 'failed',
            'active' => $run->isActive() || $moving,
            'steps' => array_map(fn (array $step) => [
                'key' => $step['key'],
                'status' => $step['status'],
                'label' => __('tax.workflow.steps.'.$step['key']),
                'detail' => $this->stepDetail($step),
            ], $run->stepList()),
            // Changes whenever anything on the page would change: the page reloads then.
            'fingerprint' => md5(json_encode([$run->status, $reports->map(fn (TaxReport $r) => $r->id.':'.$r->status->value)->all()])),
        ];
    }

    private function stepDetail(array $step): string
    {
        $params = $step['params'] ?? [];

        return match (true) {
            $step['status'] === 'pending' || $step['status'] === 'running' => '',
            $step['key'] === 'portal' && $step['status'] === 'skipped' => __('tax.workflow.portal_skipped.'.($params['reason'] ?? 'no_access')),
            isset($params['error']) => (string) $params['error'],
            isset($params['problem']) => __('tax.workflow.problems.'.$params['problem']),
            default => __('tax.workflow.details.'.$step['key'], array_map(fn ($v) => is_bool($v) ? ($v ? '✓' : '—') : (string) $v, $params)),
        };
    }
}
