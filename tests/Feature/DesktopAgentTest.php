<?php

use App\Agent\AgentTasks;
use App\Enums\Actor;
use App\Enums\Role;
use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Models\AgentTask;
use App\Models\AuditLog;
use App\Models\CabinetPayment;
use App\Models\CompanySetting;
use App\Models\DesktopAgent;
use App\Models\PortalCheck;
use App\Models\SignatureLog;
use App\Models\SubmissionAttempt;
use App\Models\TaxPayment;
use App\Models\TaxReport;
use App\Models\User;
use App\Payments\PaymentOrchestrator;
use App\Portal\PortalAnalyzer;
use App\Portal\PortalSnapshot;
use App\Services\Platform\IntegrationSettings;
use App\Submission\Gateway\DesktopGateway;
use App\Submission\Gateway\HttpGateway;
use App\Submission\Gateway\PortalGateway;
use App\Submission\Gateway\SandboxGateway;
use App\Submission\SubmissionGates;
use App\Submission\SubmissionOrchestrator;
use App\Submission\SubmissionRefused;
use App\Submission\SubmissionVerifier;
use App\Support\Money;
use App\Tax\TaxCalculator;
use App\Tax\TaxObligations;
use App\Tax\TaxPeriod;
use App\Tax\TaxReportPreparer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/*
 * The desktop agent (agent/): Laravel queues the work and decides everything; the agent only carries it out in the
 * accountant's browser. Nothing here starts a browser: the agent's side is played over its HTTP API.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-05-10 10:00:00');
    seedTaxRules();
    CompanySetting::create(['name' => 'Test MChJ', 'stir' => '300000009', 'vat_payer' => true, 'cancel_window_hours' => 24]);
    CompanySetting::query()->update(['portal_access' => true]);
    bookDocument('out', 1_000_000, 1200, '2026-04-10');
    app(IntegrationSettings::class)->saveGateway(['driver' => 'desktop', 'timeout' => 30], null);
});

afterEach(fn () => Carbon::setTestNow());

/** A paired, online agent; returns its plain token. */
function pairedAgent(string $name = 'Buxgalter-PC'): array
{
    [$agent, $token] = DesktopAgent::pair($name, User::factory()->role(Role::Owner)->create());
    $agent->forceFill(['last_seen_at' => now()])->save();

    return [$agent, $token];
}

/** The cabinet was read: submission is allowed (and the report is not on the cabinet). */
function cabinetRead(): PortalCheck
{
    return PortalCheck::create([
        'status' => 'completed', 'portal' => 'desktop', 'actor_type' => 'user', 'actor_name' => 'Owner',
        'started_at' => now()->subMinute(), 'finished_at' => now()->subMinute(),
        'snapshot' => ['taxpayer' => ['tin' => '300000009', 'name' => null, 'vat_payer' => null, 'regime' => null], 'balances' => [], 'reports' => [], 'messages' => []],
        'findings' => [], 'summary' => [],
    ]);
}

function agentApi(string $token, string $path, array $body = [], string $method = 'postJson')
{
    return test()->withToken($token)->{$method}('/api/agent'.$path, $body);
}

function vatReport(): TaxReport
{
    return app(TaxReportPreparer::class)->prepare(TaxReportType::Vat, TaxPeriod::month(2026, 4));
}

/** The values the cabinet form would show for the approved report. */
function formValues(TaxReport $report, array $override = []): array
{
    return $override + [
        'tin' => '300000009', 'type' => 'vat', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
        'base_minor' => $report->base_minor, 'tax_minor' => $report->tax_minor, 'payable_minor' => $report->payable_minor,
    ];
}

/** The agent showed the filled form and the owner answered "yes, send it": only now may the agent run the form check. */
function confirmedForm(string $token, AgentTask $task, TaxReport $report): void
{
    agentApi($token, "/tasks/{$task->id}/preview", ['fields' => formValues($report)])->assertOk();
    app(AgentTasks::class)->confirmFinal($task->fresh(), User::query()->first());
}

/** The owner approved and sent the report: it waits for the agent. */
function queuedSubmission(): array
{
    [$agent, $token] = pairedAgent();
    cabinetRead();
    $owner = signIn(Role::Owner);
    $report = vatReport();
    app(SubmissionOrchestrator::class)->approveAndSubmit($report, $owner);

    return [$report->fresh(), AgentTask::sole(), $agent, $token];
}

it('pairs a computer only for the owner, shows the key once and stores only its hash', function () {
    signIn(Role::ChiefAccountant);
    post(route('tax.agent.pair'), ['name' => 'PC'])->assertForbidden();

    signIn(Role::Owner);
    $response = post(route('tax.agent.pair'), ['name' => 'Buxgalter-PC'])->assertRedirect(route('tax.agent'));
    $token = session('agent_token');

    expect($token)->toStartWith('bga_')->and(strlen($token))->toBe(52);
    $agent = DesktopAgent::sole();
    expect($agent->token_hash)->toBe(hash('sha256', $token))->and($agent->token_hash)->not->toContain($token);
    expect(AuditLog::query()->where('action', 'desktop_agent.paired')->value('details'))->not->toContain($token);

    // Shown on the next page, not the one after.
    get(route('tax.agent'))->assertOk()->assertSee($token);
    session()->forget('agent_token');
    get(route('tax.agent'))->assertOk()->assertDontSee($token)->assertSee('bga_');
});

it('rejects a missing, wrong or revoked key on every agent route', function () {
    [$agent, $token] = pairedAgent();

    test()->postJson('/api/agent/heartbeat', ['version' => '1'])->assertUnauthorized();
    agentApi('bga_'.str_repeat('x', 48), '/heartbeat', ['version' => '1'])->assertUnauthorized();
    agentApi($token, '/heartbeat', ['version' => '1'])->assertOk()->assertJsonPath('ok', true);

    signIn(Role::Owner);
    post(route('tax.agent.revoke', $agent))->assertRedirect();
    agentApi($token, '/tasks/claim')->assertUnauthorized();
});

it('stores only diagnostics from the heartbeat', function () {
    [$agent, $token] = pairedAgent();

    agentApi($token, '/heartbeat', ['version' => '0.1.0', 'environment' => ['eimzo_reachable' => true, 'eimzo_version' => '4.0', 'browser' => 'msedge', 'keys_on_disk' => 1, 'os' => 'windows', 'password' => 'hunter2', 'key' => 'MIIE...']])->assertOk();

    $agent->refresh();
    expect($agent->environment)->toBe(['eimzo_reachable' => true, 'eimzo_version' => '4.0', 'browser' => 'msedge', 'keys_on_disk' => 1, 'os' => 'windows'])
        ->and($agent->isOnline())->toBeTrue();
});

it('queues an approved report for the agent without signing anything or needing a vault key', function () {
    [$report, $task] = queuedSubmission();

    expect($report->status)->toBe(TaxReportStatus::Submitting)
        ->and($task->type)->toBe('submit_report')
        ->and($task->status)->toBe('queued')
        ->and($task->version_hash)->toBe($report->version_hash)
        ->and($task->payload['expected']['payable_minor'])->toBe($report->payable_minor)
        ->and($task->payload['company']['tin'])->toBe('300000009')
        ->and(SignatureLog::count())->toBe(0)
        ->and(SubmissionAttempt::count())->toBe(0);

    // No secret of any kind is in what the agent receives.
    expect(json_encode($task->payload))->not->toMatch('/password|secret|private|pfx|token/i');
});

it('refuses to queue when no agent is online, or the cabinet was not read', function () {
    $owner = signIn(Role::Owner);
    cabinetRead();
    $report = vatReport();

    app(SubmissionOrchestrator::class)->approveAndSubmit($report, $owner);
    expect($report->fresh()->status)->toBe(TaxReportStatus::Blocked)
        ->and($report->fresh()->status_note)->toContain(__('tax.gates.authority_validation'))
        ->and(AgentTask::count())->toBe(0);

    PortalCheck::query()->delete();
    pairedAgent();
    app(SubmissionOrchestrator::class)->approveAndSubmit($report->fresh(), $owner);
    expect($report->fresh()->status)->toBe(TaxReportStatus::Blocked)->and(AgentTask::count())->toBe(0);
});

it('runs the whole path: claim, progress, form check, signing, the cabinet answer', function () {
    [$report, $task, $agent, $token] = queuedSubmission();

    $claimed = agentApi($token, '/tasks/claim')->assertOk()->json('task');
    expect($claimed['id'])->toBe($task->id)->and($claimed['type'])->toBe('submit_report');
    agentApi($token, '/tasks/claim')->assertOk()->assertJsonPath('task', null); // one task at a time

    agentApi($token, "/tasks/{$task->id}/events", ['kind' => 'progress', 'message' => 'Signed in'])->assertOk();
    agentApi($token, "/tasks/{$task->id}/events", ['kind' => 'needs_user', 'message' => 'Enter the key password in the E-IMZO window', 'waiting_for' => 'eimzo_password'])->assertOk();
    expect($task->fresh()->status)->toBe('needs_user')->and($task->fresh()->waiting_for)->toBe('eimzo_password');
    get(route('tax.agent'))->assertOk()->assertSee(__('tax.agent.waiting_for.eimzo_password'));

    confirmedForm($token, $task, $report);
    $check = agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)])->assertOk()->json();
    expect($check['proceed'])->toBeTrue()->and($check['mismatches'])->toBe([]);
    $attempt = SubmissionAttempt::sole();
    expect($attempt->status)->toBe('sending')->and($attempt->gateway)->toBe('desktop')->and($check['idempotency_key'])->toBe($attempt->idempotency_key);

    agentApi($token, "/tasks/{$task->id}/complete", ['result' => ['status' => 'accepted', 'receipt' => 'MS-2026-41', 'pkcs7_sha256' => str_repeat('a', 64)]])->assertOk();

    $report->refresh();
    expect($report->status)->toBe(TaxReportStatus::Accepted)
        ->and($report->receipt_number)->toBe('MS-2026-41')
        ->and($attempt->fresh()->status)->toBe('accepted')
        ->and($task->fresh()->status)->toBe('completed')
        ->and(SignatureLog::sole()->signer)->toBe('eimzo')
        ->and(SignatureLog::sole()->signature_hash)->toBe(str_repeat('a', 64));
});

it('does not let the agent sign when the filled form differs from the approved report', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');

    confirmedForm($token, $task, $report);
    $response = agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report, ['payable_minor' => $report->payable_minor + 100])])->assertOk();

    expect($response->json('proceed'))->toBeFalse()->and($response->json('mismatches'))->toContain('payable_minor');
    expect($report->fresh()->status)->toBe(TaxReportStatus::Blocked)
        ->and($task->fresh()->status)->toBe('failed')
        ->and(SubmissionAttempt::count())->toBe(0)
        ->and(SignatureLog::count())->toBe(0);

    // The agent cannot talk its way past it afterwards.
    agentApi($token, "/tasks/{$task->id}/complete", ['result' => ['status' => 'accepted']])->assertStatus(409);
    expect($report->fresh()->status)->toBe(TaxReportStatus::Blocked);
});

it('treats another taxpayer or a missing value in the form as a mismatch', function (array $override) {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');

    confirmedForm($token, $task, $report);
    expect(agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report, $override)])->json('proceed'))->toBeFalse();
})->with([
    'other TIN' => [['tin' => '200000001']],
    'no TIN' => [['tin' => null]],
    'other period' => [['period_end' => '2026-05-31']],
    'amount unreadable' => [['tax_minor' => null]],
]);

it('stops a submission when the books changed after it was queued, before anything is signed', function () {
    [$report, $task, $agent, $token] = queuedSubmission();

    // A queued report is "submitting": it is frozen against recalculation, but the books can still move under it.
    bookDocument('out', 500_000, 1200, '2026-04-20');
    expect(fn () => vatReport())->toThrow(SubmissionRefused::class);

    agentApi($token, '/tasks/claim')->assertOk();
    confirmedForm($token, $task, $report);
    $response = agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)])->assertOk();

    // The gates run again at the last moment: the totals no longer match the books.
    expect($response->json('proceed'))->toBeFalse()->and($response->json('mismatches'))->toContain('totals_match');
    expect($report->fresh()->status)->toBe(TaxReportStatus::Blocked)
        ->and(SubmissionAttempt::count())->toBe(0)
        ->and(SignatureLog::count())->toBe(0);
});

it('drops a queued submission whose approval no longer holds before the agent takes it', function () {
    [$report, $task, $agent, $token] = queuedSubmission();

    $report->update(['approved_version_hash' => null]); // the approval was voided meanwhile

    agentApi($token, '/tasks/claim')->assertOk()->assertJsonPath('task', null);
    expect($task->fresh()->status)->toBe('cancelled')->and(SubmissionAttempt::count())->toBe(0);
});

it('marks the outcome unknown, never "not sent", when the agent fails after the form check', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');
    confirmedForm($token, $task, $report);
    agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)]);

    agentApi($token, "/tasks/{$task->id}/fail", ['error' => 'Browser crashed'])->assertOk();

    expect($report->fresh()->status)->toBe(TaxReportStatus::Unknown)
        ->and(SubmissionAttempt::sole()->status)->toBe('unknown');
    expect(fn () => app(SubmissionOrchestrator::class)->submit($report->fresh(), Actor::User))->toThrow(SubmissionRefused::class);
    expect(AgentTask::query()->where('type', 'submit_report')->count())->toBe(1);
});

it('blocks, with nothing signed, when the agent fails before the form check', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');

    agentApi($token, "/tasks/{$task->id}/fail", ['error' => 'E-IMZO is not running'])->assertOk();

    expect($report->fresh()->status)->toBe(TaxReportStatus::Blocked)
        ->and($report->fresh()->gate_results)->toContain(['key' => 'agent', 'passed' => false, 'params' => ['errors' => 'E-IMZO is not running']])
        ->and(SubmissionAttempt::count())->toBe(0)
        ->and(SignatureLog::count())->toBe(0);
});

it('turns a lost agent into blocked before the form check and unknown after it', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');

    Carbon::setTestNow(now()->addMinutes(AgentTask::LEASE_MINUTES + 1));
    expect(app(AgentTasks::class)->expireLeases())->toBe(1);
    expect($report->fresh()->status)->toBe(TaxReportStatus::Blocked)->and(SubmissionAttempt::count())->toBe(0);

    // Again, but the agent got as far as the form check before it disappeared.
    Carbon::setTestNow('2026-05-10 11:00:00');
    $agent->forceFill(['last_seen_at' => now()])->save();
    app(SubmissionOrchestrator::class)->approveAndSubmit($report->fresh(), User::query()->first());
    $second = AgentTask::query()->latest('id')->first();
    agentApi($token, '/tasks/claim');
    confirmedForm($token, $second, $report->fresh());
    agentApi($token, "/tasks/{$second->id}/form-check", ['fields' => formValues($report->fresh())])->assertOk();

    Carbon::setTestNow(now()->addMinutes(AgentTask::LEASE_MINUTES + 1));
    app(AgentTasks::class)->expireLeases();
    expect($report->fresh()->status)->toBe(TaxReportStatus::Unknown)->and(SubmissionAttempt::query()->latest('id')->first()->status)->toBe('unknown');
});

it('does not declare a delivery interrupted while its agent is still reporting in', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');
    confirmedForm($token, $task, $report);
    agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)]);

    // The accountant is slowly typing the E-IMZO password; the agent keeps the lease alive.
    Carbon::setTestNow(now()->addMinutes(SubmissionVerifier::STALE_SENDING_MINUTES + 1));
    agentApi($token, "/tasks/{$task->id}/events", ['kind' => 'needs_user', 'message' => 'Confirm in E-IMZO', 'waiting_for' => 'eimzo_confirmation'])->assertOk();

    expect(app(SubmissionVerifier::class)->recoverInterrupted())->toBe(0)
        ->and(SubmissionAttempt::sole()->status)->toBe('sending')
        ->and($report->fresh()->status)->toBe(TaxReportStatus::Submitting);
});

it('treats a result that skipped the form check as unknown', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');

    agentApi($token, "/tasks/{$task->id}/complete", ['result' => ['status' => 'accepted', 'receipt' => 'FAKE-1']])->assertOk();

    expect($report->fresh()->status)->toBe(TaxReportStatus::Unknown)
        ->and($report->fresh()->receipt_number)->toBe('FAKE-1')
        ->and(SubmissionAttempt::sole()->status)->toBe('unknown');
});

it('keeps one computer from touching another computer\'s task, and tells a cancelled task to stop', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    [, $otherToken] = pairedAgent('Other-PC');
    agentApi($token, '/tasks/claim');

    agentApi($otherToken, "/tasks/{$task->id}/events", ['kind' => 'progress', 'message' => 'x'])->assertNotFound();
    agentApi($otherToken, "/tasks/{$task->id}/complete", ['result' => ['status' => 'accepted']])->assertNotFound();

    signIn(Role::Owner);
    post(route('tax.agent.cancel', $task))->assertRedirect();
    agentApi($token, "/tasks/{$task->id}/events", ['kind' => 'progress', 'message' => 'still here'])->assertStatus(409)->assertJsonPath('cancelled', true);
    expect($report->fresh()->status)->toBe(TaxReportStatus::Prepared);
});

it('cannot cancel a task once signing may have started', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');
    confirmedForm($token, $task, $report);
    agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)]);

    signIn(Role::Owner);
    post(route('tax.agent.cancel', $task))->assertSessionHas('error');
    expect($task->fresh()->status)->toBe('claimed');
});

it('turns a cabinet snapshot from the agent into a normal cabinet check, normalised and compared with the books', function () {
    [$agent, $token] = pairedAgent();
    $owner = signIn(Role::Owner);

    post(route('tax.agent.check'))->assertRedirect(route('tax.agent'));
    post(route('tax.agent.check')); // one open cabinet task at a time
    $task = AgentTask::sole();
    $check = PortalCheck::sole();
    expect($check->status)->toBe('running');

    agentApi($token, '/tasks/claim');
    agentApi($token, "/tasks/{$task->id}/complete", ['result' => ['snapshot' => [
        'taxpayer' => ['tin' => '300000009', 'name' => 'Test MChJ', 'evil' => '<script>'],
        'reports' => [['type' => 'vat', 'form' => 'QQS', 'name' => 'QQS', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'status' => 'accepted', 'submitted_at' => '2026-04-10', 'receipt' => 'R-1', 'extra' => 'ignored']],
        'balances' => [['tax' => 'vat', 'name' => 'QQS', 'debt_minor' => 1.5, 'overpaid_minor' => 0, 'penalty_minor' => 0]],
    ]]])->assertOk();

    expect($check->fresh()->status)->toBe('failed') // money that is not integer tiyin is refused, like the robot's
        ->and($task->fresh()->status)->toBe('failed');

    // A clean snapshot.
    post(route('tax.agent.check'));
    $task = AgentTask::query()->latest('id')->first();
    agentApi($token, '/tasks/claim');
    agentApi($token, "/tasks/{$task->id}/complete", ['result' => ['snapshot' => [
        'taxpayer' => ['tin' => '300000009', 'name' => 'Test MChJ'],
        'reports' => [['type' => 'vat', 'form' => 'QQS', 'name' => 'QQS', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'status' => 'accepted', 'submitted_at' => '2026-04-10', 'receipt' => 'R-1']],
        'balances' => [], 'messages' => [],
    ]]])->assertOk();

    $latest = PortalCheck::latestCompleted();
    expect($latest->portal)->toBe('desktop')->and($latest->snapshot['reports'][0]['receipt'])->toBe('R-1')
        ->and($latest->snapshot['taxpayer'])->not->toHaveKey('evil')
        ->and($latest->actor_name)->toBe($owner->name);
});

it('settles an open delivery from a later cabinet read by the agent', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');
    confirmedForm($token, $task, $report);
    agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)]);
    agentApi($token, "/tasks/{$task->id}/fail", ['error' => 'lost connection'])->assertOk();
    expect($report->fresh()->status)->toBe(TaxReportStatus::Unknown);

    // Verification asks the agent to look at the cabinet again.
    Carbon::setTestNow(now()->addMinutes(5));
    $agent->forceFill(['last_seen_at' => now()])->save();
    app(SubmissionVerifier::class)->verify($report->fresh());
    $look = AgentTask::query()->where('type', 'portal_check')->sole();

    agentApi($token, '/tasks/claim');
    agentApi($token, "/tasks/{$look->id}/complete", ['result' => ['snapshot' => [
        'taxpayer' => ['tin' => '300000009'],
        'reports' => [['type' => 'vat', 'form' => 'QQS', 'name' => 'QQS', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'status' => 'accepted', 'submitted_at' => '2026-05-10', 'receipt' => 'MS-77']],
    ]]])->assertOk();

    expect($report->fresh()->status)->toBe(TaxReportStatus::Accepted)->and($report->fresh()->receipt_number)->toBe('MS-77');
});

it('strips control characters from what the agent reports and caps its length', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');

    agentApi($token, "/tasks/{$task->id}/events", ['kind' => 'progress', 'message' => "ok\x00\x1b[31mred\r\nnext"])->assertOk();
    expect($task->fresh()->events[0]['message'])->toBe('ok [31mred next');

    agentApi($token, "/tasks/{$task->id}/events", ['kind' => 'needs_user', 'message' => 'x', 'waiting_for' => 'format_c_drive'])->assertStatus(422);
    agentApi($token, "/tasks/{$task->id}/events", ['kind' => 'progress', 'message' => str_repeat('a', 301)])->assertStatus(422);
});

it('tells the agent which login to use and which identifiers the cabinet may show', function () {
    $owner = signIn(Role::Owner);

    $task = app(AgentTasks::class)->requestPortalCheck(Actor::User, $owner->name);
    expect($task->payload['company']['pinfl'])->toBeNull()
        ->and($task->payload['portal']['login_type'])->toBe('legal');

    $task->update(['status' => 'cancelled']);
    CompanySetting::query()->update(['pinfl' => '62510026590062']);

    $second = app(AgentTasks::class)->requestPortalCheck(Actor::User, $owner->name);
    expect($second->payload['company'])->toMatchArray(['tin' => '300000009', 'pinfl' => '62510026590062'])
        ->and($second->payload['portal']['login_type'])->toBe('individual');
});

it('saves a PINFL of 14 digits and refuses anything else', function () {
    signIn(Role::Owner);

    put(route('settings.company.update'), ['name' => 'Test MChJ', 'stir' => '300000009', 'pinfl' => '123'])->assertSessionHasErrors('pinfl');
    put(route('settings.company.update'), ['name' => 'Test MChJ', 'stir' => '300000009', 'pinfl' => '62510026590062'])->assertSessionHasNoErrors();

    expect(CompanySetting::firstOrFail()->pinfl)->toBe('62510026590062');
});

it('lets the owner link the cabinet the agent saw to the company with one click, and only then', function () {
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);
    post(route('tax.agent.check'));
    $task = AgentTask::sole();
    agentApi($token, '/tasks/claim');

    // The agent reports the identifier it saw: the page offers the one-click link.
    agentApi($token, "/tasks/{$task->id}/fail", ['error' => 'Wrong taxpayer: ...', 'seen_identifier' => '62510026590062'])->assertOk();
    expect($task->fresh()->result['seen_identifier'])->toBe('62510026590062');
    get(route('tax.agent'))->assertOk()->assertSee('62510026590062')->assertSee(__('tax.agent.link_button'));

    // Not just anybody: linking a taxpayer to the company is the owner's decision.
    signIn(Role::ChiefAccountant);
    post(route('tax.agent.link', $task))->assertForbidden();
    expect(CompanySetting::firstOrFail()->pinfl)->toBeNull();

    signIn(Role::Owner);
    post(route('tax.agent.link', $task))->assertRedirect(route('tax.agent'))->assertSessionHas('status');
    expect(CompanySetting::firstOrFail()->pinfl)->toBe('62510026590062')
        ->and(AuditLog::query()->where('action', 'company.pinfl_linked_from_cabinet')->exists())->toBeTrue();

    // Never overwritten, and the button is gone once a PINFL is set.
    CompanySetting::query()->update(['pinfl' => '11111111111111']);
    post(route('tax.agent.link', $task))->assertSessionHas('error');
    expect(CompanySetting::firstOrFail()->pinfl)->toBe('11111111111111');
    get(route('tax.agent'))->assertDontSee(__('tax.agent.link_button'));
});

it('refuses a malformed identifier from the agent and links nothing without one', function () {
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);
    post(route('tax.agent.check'));
    $task = AgentTask::sole();
    agentApi($token, '/tasks/claim');

    agentApi($token, "/tasks/{$task->id}/fail", ['error' => 'x', 'seen_identifier' => '12abc'])->assertStatus(422);
    agentApi($token, "/tasks/{$task->id}/fail", ['error' => 'The site shows no ERI keys.'])->assertOk();

    post(route('tax.agent.link', $task))->assertSessionHas('error');
    expect(CompanySetting::firstOrFail()->pinfl)->toBeNull();
});

it('never lets a demo company file through a real channel, but the sandbox still accepts it', function () {
    CompanySetting::query()->update(['is_demo' => true]);
    [$agent] = pairedAgent();
    cabinetRead();
    $owner = signIn(Role::Owner);
    $report = vatReport();

    app(SubmissionOrchestrator::class)->approveAndSubmit($report, $owner);
    expect($report->fresh()->status)->toBe(TaxReportStatus::Blocked)
        ->and($report->fresh()->status_note)->toContain(__('tax.gates.authority_validation'))
        ->and(AgentTask::where('type', 'submit_report')->count())->toBe(0);

    $gateways = [
        new DesktopGateway,
        app(HttpGateway::class),
        app(PortalGateway::class),
    ];
    foreach ($gateways as $gateway) {
        expect($gateway->validate($report->fresh(), CompanySetting::firstOrFail()))->toContain(__('tax.gateway_errors.demo_company'));
    }
    expect((new SandboxGateway)->validate($report->fresh(), CompanySetting::firstOrFail()))->not->toContain(__('tax.gateway_errors.demo_company'));
});

it('recognises the entrepreneur by the PINFL when comparing the cabinet with the books', function () {
    $company = CompanySetting::firstOrFail();
    $snapshot = fn (string $tin) => PortalSnapshot::normalize(['taxpayer' => ['tin' => $tin], 'reports' => []]);
    $analyze = fn (string $tin) => app(PortalAnalyzer::class)->analyze($snapshot($tin), $company->fresh(), []);

    expect($analyze('62510026590062')['summary'])->toHaveKey('tin_mismatch');

    $company->update(['pinfl' => '62510026590062']);
    expect($analyze('62510026590062')['summary'])->not->toHaveKey('tin_mismatch')
        ->and($analyze('62510026590063')['summary'])->toHaveKey('tin_mismatch');
});

it('links a real company to the cabinet on its first sign-in, with nothing typed, and never changes it afterwards', function () {
    CompanySetting::query()->update(['stir' => null, 'is_demo' => false]);
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);
    $snapshot = fn (string $tin) => ['taxpayer' => ['tin' => $tin], 'reports' => [['type' => 'turnover', 'form' => 'Расчет налога с оборота', 'name' => 'x', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => 'accepted', 'submitted_at' => '2026-10-12', 'receipt' => '9001']]];

    post(route('tax.agent.check'));
    agentApi($token, '/tasks/claim');
    agentApi($token, '/tasks/'.AgentTask::latest('id')->value('id').'/complete', ['result' => ['snapshot' => $snapshot('62510026590062')]])->assertOk();

    expect(CompanySetting::firstOrFail()->pinfl)->toBe('62510026590062')
        ->and(AuditLog::query()->where('action', 'company.linked_on_first_sign_in')->exists())->toBeTrue();
    get(route('tax.agent'))->assertOk()->assertSee(__('tax.agent.view_result'));

    // A later check that shows another identifier does not relink the company.
    post(route('tax.agent.check'));
    agentApi($token, '/tasks/claim');
    agentApi($token, '/tasks/'.AgentTask::latest('id')->value('id').'/complete', ['result' => ['snapshot' => $snapshot('62510026590099')]])->assertOk();
    expect(CompanySetting::firstOrFail()->pinfl)->toBe('62510026590062');
});

it('does not link a demo company to a real cabinet on first sign-in', function () {
    CompanySetting::query()->update(['stir' => null, 'is_demo' => true]);
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);

    post(route('tax.agent.check'));
    agentApi($token, '/tasks/claim');
    agentApi($token, '/tasks/'.AgentTask::latest('id')->value('id').'/complete', ['result' => ['snapshot' => ['taxpayer' => ['tin' => '62510026590062'], 'reports' => []]]])->assertOk();

    expect(CompanySetting::firstOrFail()->pinfl)->toBeNull();
});

it('does not call a tax overdue when the cabinet files it for another period length than the calendar expects', function () {
    CompanySetting::query()->update(['tax_regime' => 'turnover', 'vat_payer' => false]);
    $company = CompanySetting::firstOrFail();
    $monthly = fn (int $m, string $receipt) => ['type' => 'turnover', 'form' => 'Расчет налога с оборота', 'name' => 'x', 'period_start' => sprintf('2026-%02d-01', $m), 'period_end' => date('Y-m-t', strtotime(sprintf('2026-%02d-01', $m))), 'status' => 'accepted', 'submitted_at' => '2026-05-01', 'receipt' => $receipt];
    $snapshot = PortalSnapshot::normalize(['taxpayer' => ['tin' => null], 'reports' => [$monthly(1, '1'), $monthly(2, '2'), $monthly(3, '3')]]);

    $result = app(PortalAnalyzer::class)->analyze($snapshot, $company, app(TaxObligations::class)->calendar($company, 3), CarbonImmutable::parse('2026-05-10'));
    $keys = collect($result['findings'])->pluck('key');

    expect($keys)->toContain('period_mismatch')->not->toContain('overdue')->not->toContain('missing')->not->toContain('other_reports')
        ->and($result['summary']['submitted'])->toBe(3)
        ->and($result['summary']['overdue'])->toBe(0);
});

it('shows the cabinet page without the sandbox note and with the real source when the agent read it', function () {
    signIn(Role::Owner);
    PortalCheck::create([
        'status' => 'completed', 'portal' => 'desktop', 'actor_type' => 'user', 'actor_name' => 'Owner',
        'started_at' => now()->subMinute(), 'finished_at' => now(),
        'snapshot' => ['taxpayer' => ['tin' => null, 'name' => null, 'vat_payer' => null, 'regime' => null], 'balances' => [], 'messages' => [], 'reports' => [
            ['type' => 'turnover', 'form' => 'Расчет налога с оборота', 'name' => 'x', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'status' => 'accepted', 'submitted_at' => '2026-05-02', 'receipt' => '230546841'],
        ]],
        'findings' => [['key' => 'period_mismatch', 'severity' => 'warning', 'type' => 'turnover', 'period_kind' => 'quarter', 'period_start' => '2026-01-01', 'due_date' => null, 'report_id' => null, 'params' => ['cabinet' => 'month']]],
        'summary' => ['submitted' => 1],
    ]);

    get(route('tax.portal'))->assertOk()
        ->assertSee('230546841')
        ->assertSee(__('tax.portal.kinds.month'))
        ->assertDontSee(__('tax.portal.sandbox_note'))
        ->assertDontSee('@class');
});

it('brings every tax balance and the tax authority\'s letters from the agent to the cabinet page', function () {
    CompanySetting::query()->update(['tax_regime' => 'turnover', 'is_demo' => false, 'stir' => null]);
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);

    post(route('tax.agent.check'));
    agentApi($token, '/tasks/claim');
    agentApi($token, '/tasks/'.AgentTask::latest('id')->value('id').'/complete', ['result' => ['snapshot' => [
        'taxpayer' => ['tin' => '62510026590062'],
        'reports' => [],
        'balances' => [
            ['tax' => 'property', 'name' => 'Мол-мулк солиғи', 'debt_minor' => 125_000_050, 'overpaid_minor' => 0, 'penalty_minor' => 0, 'assessed_minor' => 30_000_000, 'total_minor' => 155_000_050],
            ['tax' => 'land', 'name' => 'Ер солиғи', 'debt_minor' => 0, 'overpaid_minor' => 2_000_000, 'penalty_minor' => 0, 'assessed_minor' => 0, 'total_minor' => 0],
        ],
        'messages' => [['date' => '2026-10-20', 'title' => 'Talabnoma Test №12 (javob: 2026-10-30)', 'unread' => true]],
    ]]])->assertOk();

    $check = PortalCheck::latestCompleted();
    expect($check->summary['debt_minor'])->toBe(125_000_050)
        ->and($check->summary['overpaid_minor'])->toBe(2_000_000)
        ->and($check->summary['unread'])->toBe(1)
        ->and($check->snapshot['balances'][0]['assessed_minor'])->toBe(30_000_000);

    get(route('tax.portal'))->assertOk()->assertSee('Мол-мулк солиғи')->assertSee('Talabnoma Test');
});

it('refuses a balance that is not whole tiyin instead of showing it', function () {
    CompanySetting::query()->update(['is_demo' => false, 'stir' => null]);
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);

    post(route('tax.agent.check'));
    agentApi($token, '/tasks/claim');
    agentApi($token, '/tasks/'.AgentTask::latest('id')->value('id').'/complete', ['result' => ['snapshot' => [
        'taxpayer' => ['tin' => '62510026590062'], 'reports' => [],
        'balances' => [['tax' => 'land', 'name' => 'Ер солиғи', 'debt_minor' => 12.5, 'overpaid_minor' => 0]],
    ]]])->assertOk();

    expect(PortalCheck::latestCompleted())->toBeNull();
});

it('asks the accountant before anything is signed, and signs only after "yes"', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');

    // No answer yet: the form check is refused, nothing is recorded.
    agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)])->assertStatus(409);
    expect(SubmissionAttempt::count())->toBe(0);

    agentApi($token, "/tasks/{$task->id}/preview", ['fields' => formValues($report)])->assertOk()->assertJsonPath('mismatches', []);
    expect($task->fresh()->status)->toBe('needs_user')->and($task->fresh()->waiting_for)->toBe('final_confirmation');
    agentApi($token, "/tasks/{$task->id}/decision")->assertOk()->assertJsonPath('decision', null);
    get(route('tax.agent'))->assertOk()->assertSee(__('tax.agent.final_title'))->assertSee(__('tax.agent.final_yes'))->assertSee(__('tax.agent.final_no'));
    agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)])->assertStatus(409);

    post(route('tax.agent.send', $task))->assertRedirect();
    agentApi($token, "/tasks/{$task->id}/decision")->assertOk()->assertJsonPath('decision', 'send');
    agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)])->assertOk()->assertJsonPath('proceed', true);
    expect(SubmissionAttempt::count())->toBe(1);
});

it('sends nothing and withdraws the approval when the accountant says "no"', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');
    agentApi($token, "/tasks/{$task->id}/preview", ['fields' => formValues($report)])->assertOk();

    post(route('tax.agent.hold', $task))->assertRedirect();

    expect($task->fresh()->status)->toBe('cancelled')
        ->and($report->fresh()->status)->toBe(TaxReportStatus::Prepared)
        ->and($report->fresh()->approved_at)->toBeNull()
        ->and(SubmissionAttempt::count())->toBe(0)
        ->and(SignatureLog::count())->toBe(0);
    // The agent is told to stop.
    agentApi($token, "/tasks/{$task->id}/decision")->assertStatus(409)->assertJsonPath('cancelled', true);
    agentApi($token, "/tasks/{$task->id}/form-check", ['fields' => formValues($report)])->assertStatus(409);
});

it('shows a form that differs from the approved report and does not let it be confirmed', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');

    $response = agentApi($token, "/tasks/{$task->id}/preview", ['fields' => formValues($report, ['payable_minor' => $report->payable_minor + 100])])->assertOk();
    expect($response->json('mismatches'))->toContain('payable_minor');

    post(route('tax.agent.send', $task))->assertSessionHas('error');
    agentApi($token, "/tasks/{$task->id}/decision")->assertOk()->assertJsonPath('decision', null);
});

it('does not let anyone answer when the agent has not shown a form', function () {
    [$report, $task, $agent, $token] = queuedSubmission();
    agentApi($token, '/tasks/claim');

    post(route('tax.agent.send', $task))->assertSessionHas('error');
    post(route('tax.agent.hold', $task))->assertSessionHas('error');
    expect($task->fresh()->status)->toBe('claimed');
});

it('refuses a report for a period with no documents, money movements or payroll', function () {
    $computation = app(TaxCalculator::class)->compute(TaxReportType::Turnover, TaxPeriod::month(2026, 3), CompanySetting::query()->first());

    expect(collect($computation->issues)->where('severity', 'error')->pluck('key')->all())->toContain('no_books');
});

/** What the cabinet calculated for a month (the agent read it from the real form; September-like figures, April here). */
function cabinetDraft(array $override = []): array
{
    $lines = ['010' => 6_640_000_000, '020' => 0, '030' => 6_640_000_000, '040' => 0, '050' => 6_640_000_000, '070' => 66_400_000, '0701' => 0, '080' => 66_400_000,
        '0101' => 6_640_000_000, '0102' => 0, '0103' => 0, '0104' => 0, '0105' => 0];

    return array_replace(['type' => 'turnover', 'form' => '10190_14', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'due_date' => '2026-05-15', 'rate_bp' => 100, 'lines' => $lines], $override);
}

function cabinetCheck(array $drafts, array $reports = []): PortalCheck
{
    CompanySetting::query()->update(['tax_regime' => 'turnover', 'is_demo' => false, 'stir' => null, 'pinfl' => '62510026590062', 'vat_payer' => false]);
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);

    post(route('tax.agent.check'));
    agentApi($token, '/tasks/claim');
    agentApi($token, '/tasks/'.AgentTask::latest('id')->value('id').'/complete', ['result' => ['snapshot' => [
        'taxpayer' => ['tin' => '62510026590062'], 'reports' => $reports, 'balances' => [], 'messages' => [], 'drafts' => $drafts,
    ]]])->assertOk();

    return PortalCheck::latestCompleted();
}

it('turns the cabinet\'s own calculation into a report to check and approve, with its figures untouched', function () {
    $check = cabinetCheck([cabinetDraft()]);

    $report = TaxReport::query()->where('type', 'turnover')->whereDate('period_start', '2026-04-01')->sole();
    expect($report->source)->toBe('cabinet')
        ->and($report->base_minor)->toBe(6_640_000_000)
        ->and($report->tax_minor)->toBe(66_400_000)
        ->and($report->payable_minor)->toBe(66_400_000)
        ->and($report->due_date->toDateString())->toBe('2026-05-15')
        ->and(collect($report->issues)->pluck('key')->all())->toContain('cabinet_computed')
        ->and($report->blockingIssues())->toBe([])
        ->and($check->summary['prepared'])->toBe(1)
        ->and(collect($check->findings)->pluck('key')->all())->toContain('cabinet_ready');

    get(route('tax.reports.show', $report))->assertOk()->assertSee(__('tax.lines.cabinet_einvoice_income'));
    get(route('tax.portal'))->assertOk()->assertSee(__('tax.portal.findings.cabinet_ready', ['report' => $report->type->label(), 'period' => $report->periodLabel(), 'amount' => Money::format(66_400_000)]));
});

it('refuses cabinet figures whose own arithmetic does not add up', function () {
    cabinetCheck([cabinetDraft(['lines' => ['070' => 70_000_000] + cabinetDraft()['lines']])]);

    $report = TaxReport::query()->where('type', 'turnover')->sole();
    expect(collect($report->blockingIssues())->pluck('key')->all())->toContain('cabinet_arithmetic');
});

it('never lets a mandate submit figures the cabinet calculated, and never recalculates them from the books', function () {
    cabinetCheck([cabinetDraft()]);
    $report = TaxReport::query()->where('type', 'turnover')->sole();

    $report->forceFill(['approval_source' => 'mandate'])->save();
    $gates = collect(app(SubmissionGates::class)->check($report->fresh(), CompanySetting::query()->first(), checkSigningKey: false))->keyBy('key');
    expect($gates['cabinet_figures_need_person']['passed'])->toBeFalse()->and($gates['totals_match']['passed'])->toBeTrue();

    expect(fn () => app(TaxReportPreparer::class)->prepare(TaxReportType::Turnover, TaxPeriod::month(2026, 4)))->toThrow(SubmissionRefused::class);
});

it('prepares nothing for a month the cabinet already shows as filed', function () {
    cabinetCheck([cabinetDraft()], [['type' => 'turnover', 'form' => 'Расчет налога с оборота', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'status' => 'accepted', 'receipt' => '230546841']]);

    expect(TaxReport::query()->where('type', 'turnover')->count())->toBe(0);
});

it('carries the cabinet figures through the whole path: approval, the agent\'s form check, the accepted answer', function () {
    cabinetCheck([cabinetDraft()]);
    $report = TaxReport::query()->where('type', 'turnover')->sole();
    $owner = User::query()->first();
    cabinetRead();

    app(SubmissionOrchestrator::class)->approveAndSubmit($report->fresh(), $owner);
    $task = AgentTask::query()->where('type', 'submit_report')->sole();
    [$agent, $token] = [DesktopAgent::query()->first(), null];
    expect($report->fresh()->status)->toBe(TaxReportStatus::Submitting)
        ->and($task->payload['expected']['tin'])->toBe('62510026590062')
        ->and($task->payload['expected']['payable_minor'])->toBe(66_400_000);
});

it('shows the cabinet\'s own figures for a chosen month without creating, approving or sending anything', function () {
    CompanySetting::query()->update(['tax_regime' => 'turnover', 'is_demo' => false, 'stir' => null, 'pinfl' => '62510026590062', 'vat_payer' => false]);
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);

    post(route('tax.agent.rehearse'), ['month' => '2026-04'])->assertRedirect();
    $task = AgentTask::query()->where('type', 'rehearsal')->sole();
    post(route('tax.agent.rehearse'), ['month' => '2026-04'])->assertSessionHas('error'); // one at a time

    $claimed = agentApi($token, '/tasks/claim')->assertOk()->json('task');
    expect($claimed['type'])->toBe('rehearsal')->and($claimed['payload']['month'])->toBe(['year' => 2026, 'month' => 4]);

    agentApi($token, "/tasks/{$task->id}/complete", ['result' => ['draft' => cabinetDraft()]])->assertOk();

    expect($task->fresh()->status)->toBe('completed')
        ->and($task->fresh()->result['rehearsal']['payable_minor'])->toBe(66_400_000)
        ->and(TaxReport::count())->toBe(0)
        ->and(SubmissionAttempt::count())->toBe(0);
    get(route('tax.agent'))->assertOk()->assertSee(__('tax.agent.rehearsal_nothing_sent'))->assertSee(__('tax.lines.cabinet_einvoice_income'));
});

it('does not accept a rehearsal result that is not a one-month turnover calculation', function () {
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);
    post(route('tax.agent.rehearse'), ['month' => '2026-04']);
    $task = AgentTask::query()->where('type', 'rehearsal')->sole();
    agentApi($token, '/tasks/claim');

    agentApi($token, "/tasks/{$task->id}/complete", ['result' => ['draft' => cabinetDraft(['type' => 'vat'])]])->assertOk();
    expect($task->fresh()->status)->toBe('failed')->and(TaxReport::count())->toBe(0);
});

it('keeps the payment orders the cabinet lists and shows them with totals per tax', function () {
    $payment = fn (string $number, string $date, string $code, int $minor, bool $paid = true) => [
        'number' => $number, 'date' => $date, 'tax_code' => $code, 'tax' => $code === '100' ? 'turnover' : 'social', 'amount_minor' => $minor,
        'status_text' => $paid ? 'Банк томонидан тўланган тўлов топшириқномалар' : 'Тўланмаган', 'paid' => $paid,
        'payer_bank_code' => '00083', 'payer_account' => '20218000907449368001', 'receiver_account' => '23402000300100001010', 'year' => '2026',
    ];

    CompanySetting::query()->update(['tax_regime' => 'turnover', 'is_demo' => false, 'stir' => null, 'pinfl' => '62510026590062', 'vat_payer' => false]);
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);

    foreach ([1, 2] as $run) {
        post(route('tax.agent.check'));
        agentApi($token, '/tasks/claim');
        agentApi($token, '/tasks/'.AgentTask::latest('id')->value('id').'/complete', ['result' => ['snapshot' => [
            'taxpayer' => ['tin' => '62510026590062'], 'reports' => [], 'balances' => [], 'messages' => [],
            // the same orders again on the second read, one of them now paid
            'payments' => [$payment('18', '2026-10-24', '100', 100_000), $payment('16', '2026-10-23', '38', 200_000_000), $payment('12', '2026-08-12', '100', 100_000_000, $run === 2)],
        ]]])->assertOk();
    }

    expect(CabinetPayment::count())->toBe(3)
        ->and((bool) CabinetPayment::query()->where('order_number', '12')->value('is_paid'))->toBeTrue();

    get(route('tax.payments'))->assertOk()
        ->assertSee(__('tax.payments.taxes.100'))
        ->assertSee(Money::format(200_000_000))
        ->assertSee('•••• 8001')
        ->assertDontSee('20218000907449368001');
});

it('refuses a payment amount that is not whole tiyin instead of storing it', function () {
    CompanySetting::query()->update(['is_demo' => false, 'stir' => null]);
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);
    post(route('tax.agent.check'));
    agentApi($token, '/tasks/claim');
    agentApi($token, '/tasks/'.AgentTask::latest('id')->value('id').'/complete', ['result' => ['snapshot' => ['taxpayer' => ['tin' => null], 'payments' => [['number' => '1', 'date' => '2026-10-24', 'tax_code' => '100', 'amount_minor' => '12.5']]]]])->assertOk();

    expect(CabinetPayment::count())->toBe(0);
});

/** A real company linked to the cabinet, an online agent, an owner signed in: ready to pay. */
function payingCompany(): array
{
    CompanySetting::query()->update(['tax_regime' => 'turnover', 'is_demo' => false, 'stir' => null, 'pinfl' => '62510026590062', 'vat_payer' => false, 'tax_region' => 'Тошкент шаҳар', 'tax_district' => 'Учтепа']);
    [$agent, $token] = pairedAgent();
    signIn(Role::Owner);
    // the unnamed throttle counts every throttled route of one user together; these tests press several of them
    test()->withoutMiddleware(IlluminateRoutingMiddlewareThrottleRequests::class);

    return [$agent, $token];
}

function payForm(array $override = []): array
{
    return $override + ['tax_code' => '100', 'kind' => '08101', 'amount' => '1 000 000', 'purpose' => '', 'client_token' => 'tok_'.str_repeat('a', 20)];
}

it('turns "pay" into exactly the approved amount for the agent, once, in whole tiyin', function () {
    payingCompany();

    post(route('tax.payments.store'), payForm())->assertRedirect(route('tax.payments'));
    post(route('tax.payments.store'), payForm())->assertRedirect(); // the same opened form pressed twice

    $payment = TaxPayment::sole();
    $task = AgentTask::query()->where('type', 'pay')->sole();
    expect($payment->amount_minor)->toBe(100_000_000)
        ->and($payment->status)->toBe('queued')
        ->and($task->payload['payment'])->toMatchArray(['tax_code' => '100', 'kind' => '08101', 'amount_minor' => 100_000_000, 'district' => 'Учтепа'])
        ->and(AuditLog::query()->where('action', 'tax_payment.approved')->count())->toBe(1);
    get(route('tax.payments'))->assertOk()->assertSee(__('tax.payments.statuses.queued'));
});

it('refuses a payment for a demo company, without an online agent, or with a wrong amount', function (array $override, string $error) {
    [$agent] = payingCompany();
    if ($error === 'demo') {
        CompanySetting::query()->update(['is_demo' => true]);
    }
    if ($error === 'no_agent') {
        $agent->forceFill(['last_seen_at' => now()->subHour()])->save();
    }

    post(route('tax.payments.store'), payForm($override))->assertSessionHas('error', fn ($m) => str_contains($m, __('tax.payments.errors.'.$error)));
    expect(TaxPayment::count())->toBe(0)->and(AgentTask::query()->where('type', 'pay')->count())->toBe(0);
})->with([
    'demo' => [[], 'demo'],
    'no agent' => [[], 'no_agent'],
    'amount' => [['amount' => '0'], 'amount'],
]);

it('rejects a malformed amount or an unknown tax in the form itself', function () {
    payingCompany();

    post(route('tax.payments.store'), payForm(['amount' => '1e9']))->assertSessionHasErrors('amount');
    post(route('tax.payments.store'), payForm(['tax_code' => '999']))->assertSessionHasErrors('tax_code');
    post(route('tax.payments.store'), payForm(['kind' => '08104']))->assertSessionHasErrors('kind');
    expect(TaxPayment::count())->toBe(0);
});

it('lets the agent press the cabinet confirm button only for the approved tax, kind and amount', function () {
    [$agent, $token] = payingCompany();
    post(route('tax.payments.store'), payForm());
    $task = AgentTask::query()->where('type', 'pay')->sole();
    agentApi($token, '/tasks/claim');

    // a different amount in the cabinet's form: nothing is paid
    agentApi($token, "/tasks/{$task->id}/payment-check", ['fields' => ['tax_code' => '100', 'kind' => '08101', 'amount_minor' => 100_000_100]])->assertOk()->assertJsonPath('proceed', false);
    expect(TaxPayment::sole()->status)->toBe('failed')->and($task->fresh()->status)->toBe('failed');
});

it('runs the whole payment: check, outcome, and never repeats an unknown one', function () {
    [$agent, $token] = payingCompany();
    post(route('tax.payments.store'), payForm());
    $task = AgentTask::query()->where('type', 'pay')->sole();
    agentApi($token, '/tasks/claim');

    agentApi($token, "/tasks/{$task->id}/payment-check", ['fields' => ['tax_code' => '100', 'kind' => '08101', 'amount_minor' => 100_000_000]])->assertOk()->assertJsonPath('proceed', true);
    expect(TaxPayment::sole()->status)->toBe('sending')->and(TaxPayment::sole()->attempt_started_at)->not->toBeNull();
    // a second check is a protocol error
    agentApi($token, "/tasks/{$task->id}/payment-check", ['fields' => ['tax_code' => '100', 'kind' => '08101', 'amount_minor' => 100_000_000]])->assertStatus(409);

    // the browser dies after the confirm button: the outcome is unknown, not "not paid"
    agentApi($token, "/tasks/{$task->id}/fail", ['error' => 'Browser crashed'])->assertOk();
    $payment = TaxPayment::sole();
    expect($payment->status)->toBe('unknown');

    // an identical payment is refused until this one is settled
    post(route('tax.payments.store'), payForm(['client_token' => 'tok_'.str_repeat('b', 20)]))->assertSessionHas('error');
    expect(TaxPayment::count())->toBe(1);

    // the cabinet's list later shows the order: the payment is settled by it, never by a guess
    PortalCheck::query()->delete();
    Cache::flush(); // the throttle counters of the presses above
    post(route('tax.agent.check'));
    agentApi($token, '/tasks/claim');
    agentApi($token, '/tasks/'.AgentTask::latest('id')->value('id').'/complete', ['result' => ['snapshot' => [
        'taxpayer' => ['tin' => '62510026590062'], 'reports' => [], 'balances' => [], 'messages' => [],
        'payments' => [['number' => '19', 'date' => now()->toDateString(), 'tax_code' => '100', 'tax' => 'turnover', 'amount_minor' => 100_000_000, 'status_text' => 'Банк томонидан тўланган', 'paid' => true]],
    ]]])->assertOk();

    expect($payment->fresh()->status)->toBe('paid')->and($payment->fresh()->order_number)->toBe('19');
});

it('takes a queued payment back, but not one the agent already checked', function () {
    [$agent, $token] = payingCompany();
    post(route('tax.payments.store'), payForm());
    $payment = TaxPayment::sole();

    post(route('tax.payments.cancel', $payment))->assertRedirect();
    expect($payment->fresh()->status)->toBe('cancelled')->and(AgentTask::query()->where('type', 'pay')->value('status'))->toBe('cancelled');

    post(route('tax.payments.store'), payForm(['client_token' => 'tok_'.str_repeat('c', 20)]));
    $task = AgentTask::query()->where('type', 'pay')->latest('id')->first();
    agentApi($token, '/tasks/claim');
    agentApi($token, "/tasks/{$task->id}/payment-check", ['fields' => ['tax_code' => '100', 'kind' => '08101', 'amount_minor' => 100_000_000]]);

    post(route('tax.payments.cancel', TaxPayment::latest('id')->first()))->assertSessionHas('error');
});

it('checks a payment without paying: the form is matched, the server never lets it reach the confirm button', function () {
    [$agent, $token] = payingCompany();
    post(route('tax.payments.store'), payForm(['dry_run' => '1']))->assertRedirect();
    $payment = TaxPayment::sole();
    $task = AgentTask::query()->where('type', 'pay')->sole();
    expect($payment->dry_run)->toBeTrue()->and($task->payload['payment']['dry_run'])->toBeTrue();

    agentApi($token, '/tasks/claim');
    $answer = agentApi($token, "/tasks/{$task->id}/payment-check", ['fields' => ['tax_code' => '100', 'kind' => '08101', 'amount_minor' => 100_000_000]])->assertOk();

    expect($answer->json('proceed'))->toBeFalse()->and($answer->json('dry_run'))->toBeTrue()
        ->and($payment->fresh()->status)->toBe('checked')
        ->and($payment->fresh()->attempt_started_at)->toBeNull()
        ->and($task->fresh()->status)->toBe('completed');
    // a payment that was only checked never settles against the cabinet's list
    expect(app(PaymentOrchestrator::class)->reconcile())->toBe(0);
});

it('still refuses a checked form that differs from the approved payment', function () {
    [$agent, $token] = payingCompany();
    post(route('tax.payments.store'), payForm(['dry_run' => '1']));
    $task = AgentTask::query()->where('type', 'pay')->sole();
    agentApi($token, '/tasks/claim');

    agentApi($token, "/tasks/{$task->id}/payment-check", ['fields' => ['tax_code' => '47', 'kind' => '08101', 'amount_minor' => 100_000_000]])->assertOk()->assertJsonPath('proceed', false);
    expect(TaxPayment::sole()->status)->toBe('failed');
});
