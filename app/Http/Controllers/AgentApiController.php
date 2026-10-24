<?php

namespace App\Http\Controllers;

use App\Agent\AgentTasks;
use App\Http\Requests\Agent\AgentCompleteRequest;
use App\Http\Requests\Agent\AgentEventRequest;
use App\Http\Requests\Agent\AgentFailRequest;
use App\Http\Requests\Agent\AgentFormCheckRequest;
use App\Http\Requests\Agent\AgentHeartbeatRequest;
use App\Http\Requests\Agent\AgentPaymentCheckRequest;
use App\Http\Requests\Agent\AgentPreviewRequest;
use App\Models\AgentTask;
use App\Models\DesktopAgent;
use App\Submission\SubmissionRefused;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The desktop agent's side of App\Agent\AgentTasks. Thin: authentication is the token (AuthenticateDesktopAgent),
 * every decision is in AgentTasks, and a task can only be touched by the computer that claimed it.
 */
class AgentApiController extends Controller
{
    /** How often an idle agent asks for work. */
    private const POLL_SECONDS = 10;

    public function heartbeat(AgentHeartbeatRequest $request): JsonResponse
    {
        $data = $request->validated();
        $env = $data['environment'] ?? [];

        $this->agent($request)->forceFill([
            'agent_version' => $data['version'],
            'environment' => [
                'eimzo_reachable' => (bool) ($env['eimzo_reachable'] ?? false),
                'eimzo_version' => isset($env['eimzo_version']) ? mb_substr((string) $env['eimzo_version'], 0, 20) : null,
                'browser' => isset($env['browser']) ? mb_substr((string) $env['browser'], 0, 40) : null,
                'keys_on_disk' => isset($env['keys_on_disk']) ? (int) $env['keys_on_disk'] : null,
                'os' => isset($env['os']) ? mb_substr((string) $env['os'], 0, 40) : null,
            ],
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['ok' => true, 'poll_seconds' => self::POLL_SECONDS, 'server_time' => now()->toIso8601String()]);
    }

    public function claim(Request $request, AgentTasks $tasks): JsonResponse
    {
        $task = $tasks->claim($this->agent($request));

        return response()->json(['task' => $task === null ? null : [
            'id' => $task->id,
            'type' => $task->type,
            'payload' => $task->payload,
            'lease_minutes' => AgentTask::LEASE_MINUTES,
        ]]);
    }

    public function event(AgentEventRequest $request, AgentTask $task, AgentTasks $tasks): JsonResponse
    {
        return $this->owned($request, $task, function () use ($request, $task, $tasks) {
            $data = $request->validated();
            $tasks->event($task, $data['kind'], $data['message'], $data['waiting_for'] ?? null);

            return ['ok' => true, 'cancelled' => false];
        });
    }

    /** The values read back from the filled form; the agent may sign only if "proceed" is true. */
    public function formCheck(AgentFormCheckRequest $request, AgentTask $task, AgentTasks $tasks): JsonResponse
    {
        return $this->owned($request, $task, fn () => $tasks->formCheck($task, $request->validated('fields')));
    }

    /** The cabinet's payment form as filled, before the agent presses the confirm button; it may go on only if "proceed" is true. */
    public function paymentCheck(AgentPaymentCheckRequest $request, AgentTask $task, AgentTasks $tasks): JsonResponse
    {
        return $this->owned($request, $task, fn () => $tasks->paymentCheck($task, $request->validated('fields')));
    }

    /** The filled form, before anything is signed: the accountant is asked in the panel whether to send it. */
    public function preview(AgentPreviewRequest $request, AgentTask $task, AgentTasks $tasks): JsonResponse
    {
        return $this->owned($request, $task, fn () => $tasks->previewForConfirmation($task, $request->validated('fields')));
    }

    /** "Has the accountant answered?" A "do not send" answer closes the task, so the agent sees it as task_closed. */
    public function decision(Request $request, AgentTask $task, AgentTasks $tasks): JsonResponse
    {
        return $this->owned($request, $task, fn () => ['decision' => $tasks->finalDecision($task)]);
    }

    public function complete(AgentCompleteRequest $request, AgentTask $task, AgentTasks $tasks): JsonResponse
    {
        return $this->owned($request, $task, function () use ($request, $task, $tasks) {
            $tasks->complete($task, $request->validated('result'));

            return ['ok' => true];
        });
    }

    public function fail(AgentFailRequest $request, AgentTask $task, AgentTasks $tasks): JsonResponse
    {
        return $this->owned($request, $task, function () use ($request, $task, $tasks) {
            $tasks->fail($task, $request->validated('error'), $request->validated('seen_identifier'));

            return ['ok' => true];
        });
    }

    /** @param  Closure(): array<string, mixed>  $action */
    private function owned(Request $request, AgentTask $task, Closure $action): JsonResponse
    {
        // Another computer's task does not exist for this one.
        abort_unless($task->desktop_agent_id === $this->agent($request)->id, 404);

        if (! in_array($task->status, ['claimed', 'needs_user'], true)) {
            // Cancelled by a person, or expired: the agent must stop.
            return response()->json(['error' => 'task_closed', 'status' => $task->status, 'cancelled' => true], 409);
        }

        try {
            return response()->json($action());
        } catch (SubmissionRefused $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
    }

    private function agent(Request $request): DesktopAgent
    {
        return $request->attributes->get('desktop_agent');
    }
}
