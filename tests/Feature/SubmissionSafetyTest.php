<?php

use App\Enums\Actor;
use App\Enums\Role;
use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\PortalCheck;
use App\Models\ReportApproval;
use App\Models\SignatureLog;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;
use App\Services\Platform\IntegrationSettings;
use App\Submission\Signing\KeyVault;
use App\Submission\SubmissionOrchestrator;
use App\Submission\SubmissionRefused;
use App\Submission\SubmissionVerifier;
use App\Tax\TaxPeriod;
use App\Tax\TaxReportPreparer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * What happens when the authority's answer is missing, late or negative: a report the authority may hold is
 * never sent twice, every delivery is recorded before it leaves, and only the authority's own answer (or a
 * person who checked the cabinet) settles it.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-05-10 10:00:00');
    seedTaxRules();
    CompanySetting::create(['name' => 'Test MChJ', 'stir' => '300000009', 'vat_payer' => true, 'cancel_window_hours' => 24]);
    bookDocument('out', 1_000_000, 1200, '2026-04-10');

    app(KeyVault::class)->store(random_bytes(512), 'secret-pass', [
        'label' => 'Test key', 'owner_name' => 'Owner', 'owner_tin' => null, 'serial_number' => 'T-1',
        'valid_from' => null, 'valid_to' => now()->addYear()->toDateString(),
    ], null);
});

afterEach(fn () => Carbon::setTestNow());

function safetyVat(): TaxReport
{
    return app(TaxReportPreparer::class)->prepare(TaxReportType::Vat, TaxPeriod::month(2026, 4));
}

/** The HTTP operator gateway, with automatic verification unless $statusPath is null. */
function useHttpGateway(?string $statusPath = '/reports/status'): void
{
    app(IntegrationSettings::class)->saveGateway([
        'driver' => 'http', 'base_url' => 'https://operator.test/api', 'submit_path' => '/reports',
        'status_path' => $statusPath, 'client_id' => 'bugalter', 'timeout' => 30,
    ], 'gw-secret');
}

function submitRequests(): array
{
    return Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/reports'))->values()->all();
}

it('marks a dropped connection as unknown, never as rejected, and refuses every resend', function () {
    useHttpGateway();
    Http::fake(['*operator.test/api/reports' => Http::failedConnection()]);

    $owner = signIn(Role::Owner);
    $report = safetyVat();

    app(SubmissionOrchestrator::class)->approveAndSubmit($report, $owner);
    $report->refresh();

    $attempt = SubmissionAttempt::sole();
    expect($report->status)->toBe(TaxReportStatus::Unknown)
        ->and($attempt->status)->toBe('unknown')
        ->and($attempt->finished_at)->not->toBeNull()
        ->and(SignatureLog::count())->toBe(1);

    // Not again: not by approval, not by a direct submit, not by recalculation, not over HTTP.
    expect(fn () => app(SubmissionOrchestrator::class)->approveAndSubmit($report->fresh(), $owner))->toThrow(SubmissionRefused::class)
        ->and(fn () => app(SubmissionOrchestrator::class)->submit($report->fresh(), Actor::User))->toThrow(SubmissionRefused::class)
        ->and(fn () => safetyVat())->toThrow(SubmissionRefused::class);

    post(route('tax.reports.approve-submit', $report))->assertSessionHas('error');

    expect(submitRequests())->toHaveCount(1)
        ->and(SignatureLog::count())->toBe(1)
        ->and($report->fresh()->status)->toBe(TaxReportStatus::Unknown);

    get(route('tax.reports.show', $report))->assertOk()
        ->assertSee(__('tax.statuses.unknown'))
        ->assertSee(__('tax.resolve.title'))
        ->assertSee(__('tax.resolve.outcomes.not_received'))
        ->assertSee(substr($attempt->idempotency_key, 0, 12));
});

it('treats 5xx and 409 as unknown, and only a 4xx refusal as rejected', function (int $status, TaxReportStatus $expected) {
    useHttpGateway();
    Http::fake(['*operator.test/api/reports' => Http::response(['errors' => ['Server said no']], $status)]);

    app(SubmissionOrchestrator::class)->approveAndSubmit(safetyVat(), signIn(Role::Owner));

    expect(TaxReport::sole()->status)->toBe($expected);
})->with([
    'server error' => [502, TaxReportStatus::Unknown],
    'conflict' => [409, TaxReportStatus::Unknown],
    'rate limited' => [429, TaxReportStatus::Unknown],
    'validation refusal' => [422, TaxReportStatus::Rejected],
]);

it('sends an idempotency key, and uses a new one only after a definite rejection', function () {
    useHttpGateway();
    Http::fake(['*operator.test/api/reports' => Http::sequence()
        ->push(['errors' => ['Form 1 line 3 is wrong']], 422)
        ->push(['status' => 'accepted', 'receipt' => 'R-2'])]);

    $owner = signIn(Role::Owner);
    app(SubmissionOrchestrator::class)->approveAndSubmit(safetyVat(), $owner);

    $report = TaxReport::sole();
    expect($report->status)->toBe(TaxReportStatus::Rejected)
        ->and($report->status_note)->toContain('Form 1 line 3 is wrong');

    app(SubmissionOrchestrator::class)->approveAndSubmit($report, $owner);
    expect($report->fresh()->status)->toBe(TaxReportStatus::Accepted)
        ->and($report->fresh()->receipt_number)->toBe('R-2');

    [[$first], [$second]] = submitRequests();
    expect($first->header('Idempotency-Key')[0])->toHaveLength(64)
        ->and($first['idempotency_key'])->toBe($first->header('Idempotency-Key')[0])
        ->and($second['idempotency_key'])->not->toBe($first['idempotency_key'])
        ->and($first->header('Authorization')[0])->toBe('Bearer gw-secret');

    expect(SubmissionAttempt::query()->orderBy('id')->pluck('status')->all())->toBe(['rejected', 'accepted']);
});

it('settles an unknown delivery from the operator answer, with the receipt', function () {
    useHttpGateway();
    Http::fake([
        '*operator.test/api/reports/status*' => Http::response(['status' => 'accepted', 'receipt' => 'R-9']),
        '*operator.test/api/reports' => Http::failedConnection(),
    ]);

    app(SubmissionOrchestrator::class)->approveAndSubmit(safetyVat(), signIn(Role::Owner));
    $attempt = SubmissionAttempt::sole();

    $summary = app(SubmissionVerifier::class)->verifyPending();

    $report = TaxReport::sole();
    expect($summary['settled'])->toBe(1)
        ->and($report->status)->toBe(TaxReportStatus::Accepted)
        ->and($report->receipt_number)->toBe('R-9')
        ->and($attempt->fresh()->status)->toBe('accepted')
        ->and($attempt->fresh()->verified_by)->toBe('gateway:http');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'idempotency_key='.$attempt->idempotency_key));
    expect(AuditLog::query()->where('action', 'tax_report.accepted')->exists())->toBeTrue();
});

it('records a rejection found by verification, so the report can be corrected and sent again', function () {
    useHttpGateway();
    Http::fake([
        '*operator.test/api/reports/status*' => Http::response(['status' => 'rejected', 'errors' => ['Duplicate period']]),
        '*operator.test/api/reports' => Http::response([], 503),
    ]);

    app(SubmissionOrchestrator::class)->approveAndSubmit(safetyVat(), signIn(Role::Owner));
    app(SubmissionVerifier::class)->verifyPending();

    expect(TaxReport::sole()->status)->toBe(TaxReportStatus::Rejected)
        ->and(TaxReport::sole()->status_note)->toContain('Duplicate period');
});

it('sends again only after the authority says it never received it, with the same idempotency key and a fresh approval', function () {
    useHttpGateway();
    Http::fake([
        '*operator.test/api/reports/status*' => Http::response(['status' => 'not_found']),
        '*operator.test/api/reports' => Http::sequence()->pushFailedConnection()->push(['status' => 'accepted', 'receipt' => 'R-3']),
    ]);

    $owner = signIn(Role::Owner);
    app(SubmissionOrchestrator::class)->approveAndSubmit(safetyVat(), $owner);
    app(SubmissionVerifier::class)->verifyPending();

    $report = TaxReport::sole();
    expect($report->status)->toBe(TaxReportStatus::Prepared)
        ->and($report->approved_version_hash)->toBeNull()
        ->and($report->autopilot_hold)->toBeFalse()
        ->and(SubmissionAttempt::sole()->status)->toBe('not_received')
        ->and(ReportApproval::sole()->invalidated_at)->not->toBeNull();

    // A fresh approval is needed; the resend carries the same key, so the operator can drop a duplicate.
    app(SubmissionOrchestrator::class)->approveAndSubmit($report, $owner);
    expect($report->fresh()->status)->toBe(TaxReportStatus::Accepted);

    [[$first], [$second]] = submitRequests();
    expect($second['idempotency_key'])->toBe($first['idempotency_key']);
});

it('leaves the outcome open when the operator cannot be asked, and never sends anything while verifying', function () {
    useHttpGateway(statusPath: null);
    Http::fake(['*operator.test/api/reports' => Http::failedConnection()]);

    app(SubmissionOrchestrator::class)->approveAndSubmit(safetyVat(), signIn(Role::Owner));
    app(SubmissionVerifier::class)->verifyPending();
    app(SubmissionVerifier::class)->verifyPending();

    expect(TaxReport::sole()->status)->toBe(TaxReportStatus::Unknown)
        ->and(SubmissionAttempt::sole()->last_checked_at)->not->toBeNull()
        ->and(SubmissionAttempt::sole()->errors)->toContain(__('tax.gateway_errors.status_not_configured'))
        ->and(submitRequests())->toHaveCount(1);
});

it('turns a delivery that died half-way into unknown instead of retrying it', function () {
    $report = safetyVat();
    $owner = signIn(Role::Owner);
    app(SubmissionOrchestrator::class)->approveForImmediateSubmission($report, $owner);

    // The worker died after writing the attempt and before the answer: the report is stuck in "submitting".
    SubmissionAttempt::create([
        'tax_report_id' => $report->id, 'idempotency_key' => SubmissionAttempt::keyFor($report), 'version_hash' => $report->version_hash,
        'gateway' => 'sandbox', 'status' => 'sending', 'actor_type' => 'user', 'actor_name' => 'Owner', 'started_at' => now(),
    ]);
    $report->update(['status' => TaxReportStatus::Submitting]);

    expect(app(SubmissionVerifier::class)->recoverInterrupted())->toBe(0); // too recent: may still be sending

    Carbon::setTestNow(now()->addMinutes(SubmissionVerifier::STALE_SENDING_MINUTES + 1));
    expect(app(SubmissionVerifier::class)->recoverInterrupted())->toBe(1)
        ->and($report->fresh()->status)->toBe(TaxReportStatus::Unknown)
        ->and(app(SubmissionOrchestrator::class)->processDue())->toBe(0);

    expect(fn () => app(SubmissionOrchestrator::class)->submit($report->fresh(), Actor::User))->toThrow(SubmissionRefused::class);
});

it('lets only a person with approve and submit settle an open outcome by hand, with a note', function () {
    useHttpGateway(statusPath: null);
    Http::fake(['*operator.test/api/reports' => Http::failedConnection()]);
    app(SubmissionOrchestrator::class)->approveAndSubmit(safetyVat(), signIn(Role::Owner));
    $report = TaxReport::sole();

    signIn(Role::ChiefAccountant); // reports.submit only
    post(route('tax.reports.resolve', $report), ['outcome' => 'accepted', 'receipt' => 'R-1', 'note' => 'Checked my.soliq.uz today'])->assertForbidden();

    signIn(Role::Owner);
    post(route('tax.reports.resolve', $report), ['outcome' => 'accepted', 'note' => 'short'])->assertSessionHasErrors(['receipt', 'note']);
    post(route('tax.reports.resolve', $report), ['outcome' => 'accepted', 'receipt' => 'MS-2026-41', 'note' => 'Checked my.soliq.uz at 11:00, it is accepted'])
        ->assertRedirect(route('tax.reports.show', $report));

    $report->refresh();
    expect($report->status)->toBe(TaxReportStatus::Accepted)
        ->and($report->receipt_number)->toBe('MS-2026-41')
        ->and(SubmissionAttempt::sole()->verified_by)->toStartWith('user:')
        ->and(AuditLog::query()->where('action', 'tax_report.resolved_manually')->value('reason'))->toBe('Checked my.soliq.uz at 11:00, it is accepted');
});

it('never declares a report the authority acknowledged as not received', function () {
    useHttpGateway();
    Http::fake(['*operator.test/api/reports' => Http::response(['status' => 'submitted', 'receipt' => 'Q-1'])]);
    $owner = signIn(Role::Owner);
    app(SubmissionOrchestrator::class)->approveAndSubmit(safetyVat(), $owner);

    $report = TaxReport::sole();
    expect($report->status)->toBe(TaxReportStatus::Submitted);

    expect(fn () => app(SubmissionVerifier::class)->resolveManually($report, $owner, 'not_received', null, 'I do not see it in the cabinet'))
        ->toThrow(SubmissionRefused::class);
});

it('settles a portal delivery only from a cabinet visit that began after it', function () {
    app(IntegrationSettings::class)->saveGateway(['driver' => 'portal', 'timeout' => 30], null);
    CompanySetting::query()->update(['portal_access' => true]);
    $report = safetyVat();
    $attempt = SubmissionAttempt::create([
        'tax_report_id' => $report->id, 'idempotency_key' => SubmissionAttempt::keyFor($report), 'version_hash' => $report->version_hash,
        'gateway' => 'portal', 'status' => 'unknown', 'actor_type' => 'user', 'actor_name' => 'Owner', 'started_at' => now(),
    ]);
    $report->update(['status' => TaxReportStatus::Unknown]);

    $row = ['type' => 'vat', 'form' => 'vat', 'name' => 'QQS', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'status' => 'accepted', 'submitted_at' => '2026-05-10', 'receipt' => 'MS-77'];
    $check = fn (string $at, array $reports) => PortalCheck::create(['status' => 'completed', 'portal' => 'sandbox', 'actor_type' => 'system', 'actor_name' => 'System',
        'started_at' => $at, 'finished_at' => $at, 'snapshot' => ['taxpayer' => [], 'balances' => [], 'reports' => $reports, 'messages' => []], 'findings' => [], 'summary' => []]);

    // A visit from before the delivery proves nothing; an empty list is not "never received".
    $check('2026-05-10 09:00:00', [$row]);
    app(SubmissionVerifier::class)->verify($report);
    expect($report->fresh()->status)->toBe(TaxReportStatus::Unknown);

    $check('2026-05-10 11:00:00', []);
    app(SubmissionVerifier::class)->verify($report->fresh());
    expect($report->fresh()->status)->toBe(TaxReportStatus::Unknown)
        ->and($attempt->fresh()->status)->toBe('unknown');

    $check('2026-05-10 12:00:00', [$row]);
    app(SubmissionVerifier::class)->verify($report->fresh());
    expect($report->fresh()->status)->toBe(TaxReportStatus::Accepted)
        ->and($report->fresh()->receipt_number)->toBe('MS-77');
});

it('refuses an approval for figures the person did not see', function () {
    $owner = signIn(Role::Owner);
    $report = safetyVat();
    $seen = $report->version_hash;

    bookDocument('out', 500_000, 1200, '2026-04-20');
    $report = safetyVat();
    expect($report->version_hash)->not->toBe($seen);

    post(route('tax.reports.approve', $report), ['version' => $seen])->assertSessionHas('error');
    expect($report->fresh()->status)->toBe(TaxReportStatus::Prepared)
        ->and(ReportApproval::count())->toBe(0);

    post(route('tax.reports.approve', $report), ['version' => $report->version_hash])->assertSessionHasNoErrors();
    expect($report->fresh()->status)->toBe(TaxReportStatus::Scheduled);

    $approval = ReportApproval::sole();
    expect($approval->version_hash)->toBe($report->version_hash)
        ->and($approval->user_id)->toBe($owner->id)
        ->and($approval->company_tin)->toBe('300000009')
        ->and($approval->payable_minor)->toBe($report->payable_minor);

    // The books change again: the approval record ends, it is not edited or deleted.
    bookDocument('out', 100_000, 1200, '2026-04-25');
    safetyVat();
    expect($approval->fresh()->invalidated_at)->not->toBeNull()
        ->and(fn () => $approval->fresh()->update(['payable_minor' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $approval->fresh()->delete())->toThrow(LogicException::class);
});
