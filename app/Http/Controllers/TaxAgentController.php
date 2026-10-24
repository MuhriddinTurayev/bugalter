<?php

namespace App\Http\Controllers;

use App\Agent\AgentTasks;
use App\Enums\Actor;
use App\Enums\Permission;
use App\Http\Requests\PairDesktopAgentRequest;
use App\Models\AgentTask;
use App\Models\DesktopAgent;
use App\Models\PortalCheck;
use App\Services\Audit;
use App\Services\CurrentCompany;
use App\Submission\Gateway\TaxAuthorityGateway;
use App\Submission\SubmissionRefused;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Kompyuter agenti": the owner pairs the accountant's Windows computer (the token is shown once), everyone with
 * the tax pages sees what the agent is doing and when it waits for a person (the E-IMZO password, a CAPTCHA).
 */
class TaxAgentController extends Controller
{
    public function index(CurrentCompany $company, TaxAuthorityGateway $gateway): View
    {
        $this->authorize('view-reports');

        $tasks = AgentTask::query()->with(['report', 'agent'])->latest('id')->limit(25)->get();

        return view('tax.agent', [
            'settings' => $company->settings(),
            'agents' => DesktopAgent::query()->latest('id')->get(),
            'tasks' => $tasks,
            'active' => $tasks->contains(fn (AgentTask $task) => $task->isOpen()),
            // What each finished cabinet check read, for the summary under the task.
            'checks' => PortalCheck::query()->whereIn('id', $tasks->map(fn (AgentTask $task) => $task->result['portal_check'] ?? null)->filter()->all())->get()->keyBy('id'),
            'gatewayName' => $gateway->name(),
            'token' => session('agent_token'),
            'host' => request()->getSchemeAndHttpHost(),
        ]);
    }

    public function pair(PairDesktopAgentRequest $request, Audit $audit): RedirectResponse
    {
        [$agent, $token] = DesktopAgent::pair($request->validated('name'), $request->user());

        $audit->record('desktop_agent.paired', ['agent' => $agent->id, 'name' => $agent->name, 'token' => $agent->token_prefix.'…']);

        // Shown once on the next page; only its hash is stored.
        return redirect()->route('tax.agent')->with('agent_token', $token)->with('status', __('tax.agent.paired'));
    }

    public function revoke(Request $request, DesktopAgent $agent, AgentTasks $tasks, Audit $audit): RedirectResponse
    {
        $this->authorize(Permission::AutopilotManage->value);

        if ($agent->revoked_at === null) {
            $agent->forceFill(['revoked_at' => now(), 'revoked_by_name' => $request->user()->name])->save();

            AgentTask::query()->where('desktop_agent_id', $agent->id)->whereIn('status', ['claimed', 'needs_user'])->get()
                ->each(fn (AgentTask $task) => $tasks->fail($task, __('tax.agent.errors.revoked')));

            $audit->record('desktop_agent.revoked', ['agent' => $agent->id, 'name' => $agent->name]);
        }

        return redirect()->route('tax.agent')->with('status', __('tax.agent.revoked'));
    }

    public function check(Request $request, CurrentCompany $company, AgentTasks $tasks): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);

        if (! $company->settings()->portal_access) {
            return back()->with('error', __('tax.portal.errors.no_access'));
        }

        if (! DesktopAgent::query()->whereNull('revoked_at')->exists()) {
            return back()->with('error', __('tax.agent.errors.none_paired'));
        }

        $tasks->requestPortalCheck(Actor::User, $request->user()->name);

        return redirect()->route('tax.agent')->with('status', __('tax.agent.check_queued'));
    }

    /**
     * The owner confirms that the cabinet the agent saw belongs to this company: its 14-digit identifier becomes the
     * company's PINFL. Only when none is set (never overwritten), only from a cabinet check that failed on exactly this.
     */
    public function link(Request $request, AgentTask $task, CurrentCompany $company, Audit $audit): RedirectResponse
    {
        $this->authorize(Permission::AutopilotManage->value);

        $seen = $task->result['seen_identifier'] ?? null;
        $settings = $company->settings();

        if ($task->type !== 'portal_check' || $task->status !== 'failed' || ! is_string($seen) || ! preg_match('/^\d{14}$/', $seen)) {
            return back()->with('error', __('tax.agent.errors.nothing_to_link'));
        }

        if (filled($settings->pinfl)) {
            return back()->with('error', __('tax.agent.errors.pinfl_already_set'));
        }

        $settings->forceFill(['pinfl' => $seen])->save();
        $company->forget();
        $audit->record('company.pinfl_linked_from_cabinet', ['task' => $task->id, 'pinfl_tail' => substr($seen, -3)], reason: __('tax.agent.link_reason'));

        return redirect()->route('tax.agent')->with('status', __('tax.agent.linked'));
    }

    /** "Show me, do not send": the cabinet's own calculation for a month, read-only. */
    public function rehearse(Request $request, AgentTasks $tasks): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        if (! DesktopAgent::query()->whereNull('revoked_at')->exists()) {
            return back()->with('error', __('tax.agent.errors.none_paired'));
        }

        try {
            $tasks->requestRehearsal((int) substr($data['month'], 0, 4), (int) substr($data['month'], 5, 2), Actor::User, $request->user()->name);
        } catch (SubmissionRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.agent')->with('status', __('tax.agent.check_queued'));
    }

    /** "Yes, send it": the final answer after the agent filled the real cabinet form. */
    public function send(Request $request, AgentTask $task, AgentTasks $tasks): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);

        try {
            $tasks->confirmFinal($task, $request->user());
        } catch (SubmissionRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.agent')->with('status', __('tax.agent.final_sent'));
    }

    /** "No, do not send": nothing is signed or sent, the approval is withdrawn. */
    public function hold(Request $request, AgentTask $task, AgentTasks $tasks): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);

        try {
            $tasks->declineFinal($task, $request->user());
        } catch (SubmissionRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.agent')->with('status', __('tax.agent.final_held'));
    }

    public function cancel(Request $request, AgentTask $task, AgentTasks $tasks): RedirectResponse
    {
        $this->authorize(Permission::ReportsApprove->value);

        try {
            $tasks->cancel($task, $request->user());
        } catch (SubmissionRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.agent')->with('status', __('tax.agent.task_cancelled'));
    }
}
