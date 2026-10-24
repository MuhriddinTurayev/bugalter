<?php

use App\Enums\Actor;
use App\Enums\AutopilotLevel;
use App\Enums\Role;
use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Models\CompanySetting;
use App\Models\Mandate;
use App\Models\SignatureLog;
use App\Models\TaxReport;
use App\Models\User;
use App\Services\CurrentCompany;
use App\Services\Platform\KillSwitch;
use App\Submission\Signing\KeyVault;
use App\Submission\SubmissionOrchestrator;
use App\Submission\SubmissionRefused;
use App\Tax\TaxPeriod;
use App\Tax\TaxReportPreparer;
use Illuminate\Support\Carbon;

use function Pest\Laravel\post;

beforeEach(function () {
    Carbon::setTestNow('2026-05-10 10:00:00');
    seedTaxRules();
    CompanySetting::create(['name' => 'Test MChJ', 'stir' => '300000009', 'vat_payer' => true, 'cancel_window_hours' => 24]);
    bookDocument('out', 1_000_000, 1200, '2026-04-10');
});

afterEach(fn () => Carbon::setTestNow());

function aprilVat(): TaxReport
{
    return app(TaxReportPreparer::class)->prepare(TaxReportType::Vat, TaxPeriod::month(2026, 4));
}

function storeDemoKey(): void
{
    app(KeyVault::class)->store(random_bytes(512), 'secret-pass', [
        'label' => 'Test key', 'owner_name' => 'Owner', 'owner_tin' => null, 'serial_number' => 'T-1',
        'valid_from' => null, 'valid_to' => now()->addYear()->toDateString(),
    ], null);
}

function orchestrator(): SubmissionOrchestrator
{
    return app(SubmissionOrchestrator::class);
}

function autopilot(AutopilotLevel $level, string $signer = 'vault'): void
{
    CompanySetting::query()->update(['autopilot_level' => $level->value, 'signer_mode' => $signer]);
    app(CurrentCompany::class)->forget();
}

it('lets only someone with reports.approve approve, and starts the cancel window instead of submitting', function () {
    $report = aprilVat();

    signIn(Role::Cashier);
    post(route('tax.reports.approve', $report))->assertForbidden();

    $chief = signIn(Role::ChiefAccountant);
    post(route('tax.reports.approve', $report))->assertForbidden(); // the owner keeps approval by default
    post(route('tax.reports.request', $report))->assertRedirect();
    expect($report->fresh()->status)->toBe(TaxReportStatus::PendingApproval);

    signIn(Role::Owner);
    post(route('tax.reports.approve', $report))->assertRedirect(route('tax.reports.show', $report));

    $report->refresh();
    expect($report->status)->toBe(TaxReportStatus::Scheduled)
        ->and($report->approval_source)->toBe('user')
        ->and($report->scheduled_at->toDateTimeString())->toBe('2026-05-11 10:00:00')
        ->and(SignatureLog::count())->toBe(0);
});

it('submits after the cancel window when every gate passes, and logs the signature', function () {
    storeDemoKey();
    $owner = User::factory()->role(Role::Owner)->create();
    orchestrator()->approveByUser(aprilVat(), $owner);

    expect(orchestrator()->processDue())->toBe(0); // still inside the window

    Carbon::setTestNow('2026-05-11 10:00:01');
    expect(orchestrator()->processDue())->toBe(1);

    $report = TaxReport::first();
    $log = SignatureLog::first();

    expect($report->status)->toBe(TaxReportStatus::Accepted)
        ->and($report->receipt_number)->toStartWith('SBX-')
        ->and(collect($report->gate_results)->every(fn ($g) => $g['passed']))->toBeTrue()
        ->and($log->tax_report_id)->toBe($report->id)
        ->and($log->signer)->toBe('sandbox')
        ->and($log->approval_ref)->toBe('user:'.$owner->id)
        // nothing secret is stored in the log
        ->and(json_encode($log->toArray()))->not->toContain('secret-pass');
});

it('blocks the submission when a gate fails, and tells the owner', function () {
    $owner = User::factory()->role(Role::Owner)->create();
    orchestrator()->approveByUser(aprilVat(), $owner);

    // No signing key in the vault.
    $report = orchestrator()->submitNow(TaxReport::first(), $owner);

    expect($report->status)->toBe(TaxReportStatus::Blocked)
        ->and(collect($report->gate_results)->firstWhere('key', 'signing_key')['passed'])->toBeFalse()
        ->and($owner->notifications()->count())->toBeGreaterThan(0);
});

it('voids the approval when the books change before submission', function () {
    storeDemoKey();
    $owner = User::factory()->role(Role::Owner)->create();
    orchestrator()->approveByUser(aprilVat(), $owner);

    bookDocument('out', 10_000, 1200, '2026-04-20');
    aprilVat();

    $report = TaxReport::first();
    expect($report->status)->toBe(TaxReportStatus::Prepared)->and($report->approved_version_hash)->toBeNull();
});

describe('autopilot and mandates', function () {
    it('refuses AI requests while the autopilot is off', function () {
        expect(fn () => orchestrator()->requestSubmission(aprilVat(), Actor::Ai))->toThrow(SubmissionRefused::class);
    });

    it('only asks for approval at levels 1 and 2', function () {
        autopilot(AutopilotLevel::Ask);
        Mandate::create(['report_types' => ['vat'], 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'amount_limit_minor' => 1_000_000_000, 'expires_at' => '2027-01-01', 'status' => 'active', 'signed_name' => 'Owner', 'signed_at' => now()]);

        expect(orchestrator()->requestSubmission(aprilVat(), Actor::Ai)->status)->toBe(TaxReportStatus::PendingApproval);
    });

    it('approves through the mandate at level 3, but the gates still block unverified rules and amounts over the limit', function () {
        storeDemoKey();
        autopilot(AutopilotLevel::Auto);
        $mandate = Mandate::create(['report_types' => ['vat'], 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'amount_limit_minor' => 1_000_000_000, 'expires_at' => '2027-01-01', 'status' => 'active', 'signed_name' => 'Owner', 'signed_at' => now()]);

        $report = orchestrator()->requestSubmission(aprilVat(), Actor::Ai);
        expect($report->status)->toBe(TaxReportStatus::Scheduled)->and($report->mandate_id)->toBe($mandate->id);

        Carbon::setTestNow('2026-05-11 11:00');
        orchestrator()->processDue();
        $report->refresh();

        expect($report->status)->toBe(TaxReportStatus::Blocked)
            ->and(collect($report->gate_results)->firstWhere('key', 'rules_verified')['passed'])->toBeFalse();

        // Verified rules, but a limit below the payable amount.
        seedTaxRules(verified: true);
        aprilVat(); // new rule versions → recalculated, approval voided
        $mandate->update(['amount_limit_minor' => 100]);

        $report = orchestrator()->requestSubmission(TaxReport::first(), Actor::Autopilot);
        Carbon::setTestNow('2026-05-12 12:00');
        orchestrator()->processDue();

        expect($report->fresh()->status)->toBe(TaxReportStatus::Blocked)
            ->and(collect($report->fresh()->gate_results)->firstWhere('key', 'mandate_amount')['passed'])->toBeFalse();

        // Within the limit: submitted.
        $mandate->update(['amount_limit_minor' => 1_000_000_000]);
        orchestrator()->requestSubmission($report->fresh(), Actor::Autopilot);
        Carbon::setTestNow('2026-05-13 13:00');
        orchestrator()->processDue();

        expect($report->fresh()->status)->toBe(TaxReportStatus::Accepted)
            ->and(SignatureLog::first()->approval_ref)->toBe('mandate:'.$mandate->id);
    });

    it('stops every mandate submission while the platform kill switch is on', function () {
        storeDemoKey();
        seedTaxRules(verified: true);
        autopilot(AutopilotLevel::Auto);
        Mandate::create(['report_types' => ['vat'], 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'amount_limit_minor' => 1_000_000_000, 'expires_at' => '2027-01-01', 'status' => 'active', 'signed_name' => 'Owner', 'signed_at' => now()]);
        KillSwitch::set(true, 'incident', 'Admin');

        orchestrator()->requestSubmission(aprilVat(), Actor::Autopilot);
        Carbon::setTestNow('2026-05-11 11:00');
        orchestrator()->processDue();

        expect(TaxReport::first()->status)->toBe(TaxReportStatus::Blocked)
            ->and(collect(TaxReport::first()->gate_results)->firstWhere('key', 'kill_switch')['passed'])->toBeFalse();
    });

    it('lets the owner cancel during the window and pull the kill switch', function () {
        $owner = signIn(Role::Owner);
        orchestrator()->approveByUser(aprilVat(), $owner);

        post(route('tax.reports.cancel', TaxReport::first()))->assertRedirect();
        expect(TaxReport::first()->status)->toBe(TaxReportStatus::Prepared)->and(TaxReport::first()->autopilot_hold)->toBeTrue();

        autopilot(AutopilotLevel::Prepare);
        orchestrator()->approveByUser(TaxReport::first(), $owner);
        post(route('autopilot.kill-switch'))->assertRedirect();

        expect(TaxReport::first()->status)->toBe(TaxReportStatus::Prepared)
            ->and(CompanySetting::first()->autopilot_level)->toBe(AutopilotLevel::Off)
            ->and(CompanySetting::first()->autopilot_paused_at)->not->toBeNull();
    });
});

it('waits for the owner to sign on their device, then submits', function () {
    autopilot(AutopilotLevel::Off, 'device');
    $owner = signIn(Role::Owner);
    orchestrator()->approveByUser(aprilVat(), $owner);

    $report = orchestrator()->submitNow(TaxReport::first(), $owner);
    expect($report->status)->toBe(TaxReportStatus::AwaitingSignature);

    post(route('tax.reports.signature', $report), ['signature' => 'not base64 !!'])->assertSessionHasErrors('signature');
    post(route('tax.reports.signature', $report), ['signature' => base64_encode(random_bytes(96))])->assertRedirect();

    expect($report->fresh()->status)->toBe(TaxReportStatus::Accepted)
        ->and(SignatureLog::first()->signer)->toBe('device');
});

it('shows the calendar and a report page with its actions', function () {
    signIn(Role::Owner);
    $report = aprilVat();

    $this->get(route('tax.index'))->assertOk()->assertSee(__('tax.calendar'))->assertSee(__('tax.types.vat'));
    $this->get(route('tax.reports.show', $report))->assertOk()
        ->assertSee(__('tax.lines.output_vat_total'))
        ->assertSee(__('tax.approve'))
        ->assertSee(__('tax.unverified'));

    signIn(Role::Cashier);
    $this->get(route('tax.index'))->assertForbidden();
});
