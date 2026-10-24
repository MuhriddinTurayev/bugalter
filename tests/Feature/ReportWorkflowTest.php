<?php

use App\Enums\Role;
use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Jobs\RunReportWorkflow;
use App\Jobs\SubmitApprovedReport;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\ReportApproval;
use App\Models\ReportRun;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;
use App\Submission\Signing\KeyVault;
use App\Submission\SubmissionOrchestrator;
use App\Tax\ReportWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/*
 * "Hisobotlarni avtomatik tayyorlash" end to end: month -> analysis steps -> review checklist -> approval bound to
 * the reviewed versions with a declaration -> each report sent on its own -> its verified status.
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

/** Starts a run for April 2026 through the page and returns it (the queue is synchronous in tests). */
function startAprilRun(): ReportRun
{
    post(route('tax.runs.store'), ['month' => '2026-04'])->assertRedirect();

    return ReportRun::query()->latest('id')->firstOrFail();
}

function rowOf(ReportRun $run, TaxReportType $type): array
{
    return collect(app(ReportWorkflow::class)->rows($run))->firstWhere('type', $type->value);
}

it('runs every step for the month and records what really happened in each', function () {
    signIn(Role::Owner);
    get(route('tax.runs.create'))->assertOk()->assertSee(__('tax.workflow.title'))->assertSee('300000009');

    $run = startAprilRun();

    expect($run->status)->toBe('completed');
    $steps = collect($run->steps)->keyBy('key');
    expect($steps->keys()->all())->toBe(ReportRun::STEPS)
        ->and($steps['profile']['status'])->toBe('done')
        // No owner consent for the cabinet: skipped with the reason, not shown as done.
        ->and($steps['portal']['status'])->toBe('skipped')
        ->and($steps['portal']['params']['reason'])->toBe('no_access')
        ->and($steps['obligations']['params'])->toBe(['required' => 3, 'not_required' => 1])
        ->and($steps['prepare']['params']['prepared'])->toBeGreaterThanOrEqual(1);

    // General regime, VAT payer: VAT, profit and payroll are owed; turnover tax is not.
    expect(rowOf($run, TaxReportType::Vat)['status'])->toBe('ready_for_approval')
        ->and(rowOf($run, TaxReportType::Turnover)['status'])->toBe('not_required')
        // Profit is quarterly: April is in Q2, which is still running.
        ->and(rowOf($run, TaxReportType::Profit)['status'])->toBe('not_checked');

    expect(TaxReport::query()->where('type', 'vat')->value('status'))->toBe(TaxReportStatus::Prepared)
        ->and(AuditLog::query()->where('action', 'tax_workflow.completed')->exists())->toBeTrue();

    get(route('tax.runs.show', $run))->assertOk()
        ->assertSee(__('tax.workflow.checklist.ready_for_approval'))
        ->assertSee(__('tax.workflow.portal_skipped.no_access'));

    getJson(route('tax.runs.status', $run))->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('steps.1.status', 'skipped');
});

it('shows missing data instead of a ready report when the books have blocking issues', function () {
    signIn(Role::Owner);
    bookTransaction('income', 50_000, '2026-04-15', categoryKey: null); // unclassified

    $run = startAprilRun();
    $row = rowOf($run, TaxReportType::Vat);

    expect($row['status'])->toBe('data_missing')
        ->and($row['selectable'])->toBeFalse()
        ->and($row['source']['unclassified'])->toBe(1);
});

it('refuses a second run while one is active, and queues the work instead of doing it in the request', function () {
    signIn(Role::Owner);
    Queue::fake();

    post(route('tax.runs.store'), ['month' => '2026-04'])->assertRedirect();
    $run = ReportRun::sole();
    Queue::assertPushed(RunReportWorkflow::class, fn (RunReportWorkflow $job) => $job->runId === $run->id);
    expect($run->status)->toBe('queued');

    post(route('tax.runs.store'), ['month' => '2026-03'])->assertSessionHas('error');
    expect(ReportRun::count())->toBe(1);

    post(route('tax.runs.store'), ['month' => '2027-01'])->assertSessionHasErrors('month');
});

it('approves exactly the reviewed versions with a declaration, then sends each report on its own', function () {
    $owner = signIn(Role::Owner);
    $run = startAprilRun();
    $vat = TaxReport::query()->where('type', 'vat')->sole();

    get(route('tax.runs.confirm', [$run, 'reports' => [$vat->id]]))->assertOk()
        ->assertSee(__('tax.workflow.approve_and_submit'))
        ->assertSee(substr($vat->version_hash, 0, 12));

    // No declaration: nothing happens.
    post(route('tax.runs.approve', $run), ['versions' => [$vat->id => $vat->version_hash]])->assertSessionHasErrors('declaration');
    expect($vat->fresh()->status)->toBe(TaxReportStatus::Prepared);

    post(route('tax.runs.approve', $run), ['versions' => [$vat->id => $vat->version_hash], 'declaration' => '1'])
        ->assertRedirect(route('tax.runs.show', $run))
        ->assertSessionHas('status');

    $vat->refresh();
    expect($vat->status)->toBe(TaxReportStatus::Accepted)
        ->and($vat->receipt_number)->toStartWith('SBX-')
        ->and(SubmissionAttempt::sole()->status)->toBe('accepted');

    $approval = ReportApproval::sole();
    expect($approval->declaration)->toBeTrue()
        ->and($approval->batch)->not->toBeNull()
        ->and($approval->user_id)->toBe($owner->id)
        ->and($approval->version_hash)->toBe($vat->version_hash);

    expect(rowOf($run, TaxReportType::Vat)['status'])->toBe('accepted');
    get(route('tax.runs.show', $run))->assertOk()->assertSee(__('tax.workflow.sandbox_short'));
});

it('approves nothing when a selected report changed after it was reviewed', function () {
    signIn(Role::Owner);
    Queue::fake([SubmitApprovedReport::class]);
    $run = startAprilRun();
    $vat = TaxReport::query()->where('type', 'vat')->sole();
    $reviewed = $vat->version_hash;

    // The books change and the report is recalculated after the person looked at it.
    bookDocument('out', 300_000, 1200, '2026-04-22');
    app(ReportWorkflow::class)->run(ReportRun::create(['status' => 'queued', 'period_month' => '2026-04-01', 'actor_type' => 'user', 'actor_name' => 'Owner']));
    expect($vat->fresh()->version_hash)->not->toBe($reviewed);

    post(route('tax.runs.approve', $run), ['versions' => [$vat->id => $reviewed], 'declaration' => '1'])->assertSessionHas('error');

    expect($vat->fresh()->status)->toBe(TaxReportStatus::Prepared)
        ->and(ReportApproval::count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'tax_report.batch_refused')->exists())->toBeTrue();
    Queue::assertNothingPushed();
});

it('lets only someone with both approve and submit use the batch approval, and only for reports of the run', function () {
    signIn(Role::Owner);
    $run = startAprilRun();
    $vat = TaxReport::query()->where('type', 'vat')->sole();

    signIn(Role::ChiefAccountant);
    get(route('tax.runs.show', $run))->assertOk()->assertSee(__('tax.workflow.needs_permissions'));
    get(route('tax.runs.confirm', [$run, 'reports' => [$vat->id]]))->assertForbidden();
    post(route('tax.runs.approve', $run), ['versions' => [$vat->id => $vat->version_hash], 'declaration' => '1'])->assertForbidden();

    signIn(Role::Owner);
    post(route('tax.runs.approve', $run), ['versions' => [999 => $vat->version_hash], 'declaration' => '1'])->assertSessionHas('error');
    post(route('tax.runs.approve', $run), ['versions' => ['abc' => $vat->version_hash], 'declaration' => '1'])->assertSessionHasErrors('versions');
    expect($vat->fresh()->status)->toBe(TaxReportStatus::Prepared);
});

it('does not send a batch report whose approval was cancelled before its turn came', function () {
    $owner = signIn(Role::Owner);
    Queue::fake([SubmitApprovedReport::class]);
    $run = startAprilRun();
    $vat = TaxReport::query()->where('type', 'vat')->sole();

    post(route('tax.runs.approve', $run), ['versions' => [$vat->id => $vat->version_hash], 'declaration' => '1']);
    expect($vat->fresh()->status)->toBe(TaxReportStatus::Scheduled);

    post(route('tax.reports.cancel', $vat));
    expect($vat->fresh()->status)->toBe(TaxReportStatus::Prepared);

    (new SubmitApprovedReport($vat->id, $owner->name))->handle(app(SubmissionOrchestrator::class));
    expect($vat->fresh()->status)->toBe(TaxReportStatus::Prepared)
        ->and(SubmissionAttempt::count())->toBe(0);
});
