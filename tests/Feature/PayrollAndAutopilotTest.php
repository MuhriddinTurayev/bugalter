<?php

use App\Autopilot\AutopilotRunner;
use App\Autopilot\DailyReminders;
use App\Enums\AutopilotLevel;
use App\Enums\PayrollStatus;
use App\Enums\Role;
use App\Enums\TaxReportStatus;
use App\Models\Account;
use App\Models\AiProposal;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\Mandate;
use App\Models\PayrollRun;
use App\Models\SigningKey;
use App\Models\TaxReport;
use App\Models\Transaction;
use App\Models\User;
use App\Payroll\PayrollService;
use App\Services\CurrentCompany;
use App\Submission\Signing\KeyVault;
use App\Submission\SubmissionOrchestrator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

beforeEach(function () {
    Carbon::setTestNow('2026-05-10 10:00:00');
    seedTaxRules();
    CompanySetting::create(['name' => 'Test MChJ', 'stir' => '300000009', 'vat_payer' => true]);
    Employee::create(['full_name' => 'Ali Valiyev', 'pinfl' => '31234567890123', 'status' => 'active', 'salary_minor' => 500_000_000, 'hired_on' => '2025-01-01']);
    Employee::create(['full_name' => 'Vali Aliyev', 'pinfl' => '31234567890124', 'status' => 'dismissed', 'salary_minor' => 900_000_000]);
});

afterEach(fn () => Carbon::setTestNow());

describe('payroll', function () {
    it('is calculated from active employees, edited as a draft, approved and paid', function () {
        signIn(Role::ChiefAccountant);

        post(route('payroll.store'), ['month' => '2026-04'])->assertRedirect();
        $run = PayrollRun::first();

        expect($run->lines)->toHaveCount(1)
            ->and($run->gross_minor)->toBe(500_000_000)
            ->and($run->pit_minor)->toBe(60_000_000)
            ->and($run->social_minor)->toBe(60_000_000)
            ->and($run->net_minor)->toBe(440_000_000);

        patch(route('payroll.lines.update', [$run, $run->lines->first()]), ['gross' => '6 000 000'])->assertRedirect();
        expect($run->fresh()->gross_minor)->toBe(600_000_000)->and($run->fresh()->net_minor)->toBe(528_000_000);

        post(route('payroll.approve', $run))->assertRedirect();
        expect($run->fresh()->status)->toBe(PayrollStatus::Approved);

        patch(route('payroll.lines.update', [$run, $run->lines->first()]), ['gross' => '1'])->assertSessionHas('error');

        $account = Account::factory()->create();
        post(route('payroll.pay', $run), ['account_id' => $account->id])->assertRedirect();

        expect($run->fresh()->status)->toBe(PayrollStatus::Paid)
            ->and(Transaction::first()->amount_minor)->toBe(528_000_000)
            ->and(Transaction::first()->category->key)->toBe('salaries');
    });

    it('is not open to cashiers, and approval needs payroll.approve', function () {
        signIn(Role::Cashier);
        get(route('payroll.index'))->assertForbidden();

        $run = app(PayrollService::class)->prepare(Carbon::parse('2026-04-01')->toImmutable());
        post(route('payroll.approve', $run))->assertSessionHas('error');
        expect($run->fresh()->status)->toBe(PayrollStatus::Draft);
    });
});

describe('autopilot', function () {
    it('does nothing by itself while off', function () {
        bookDocument('out', 1_000_000, 1200, '2026-04-10');

        $summary = app(AutopilotRunner::class)->run();

        expect($summary['prepared'])->toBe(0)->and(TaxReport::count())->toBe(0)->and(PayrollRun::count())->toBe(0);
    });

    it('prepares the payroll, due reports and category proposals at level 1, without asking or submitting', function () {
        CompanySetting::query()->update(['autopilot_level' => AutopilotLevel::Prepare->value]);
        bookDocument('out', 1_000_000, 1200, '2026-04-10');
        bookTransaction('expense', 100_000, '2026-04-05', 'rent', ['description' => 'Ofis ijarasi']);
        bookTransaction('expense', 100_000, '2026-04-06', 'rent', ['description' => 'Ofis ijarasi']);
        bookTransaction('expense', 100_000, '2026-05-05', null, ['description' => 'Ofis ijarasi']);

        $summary = app(AutopilotRunner::class)->run();

        expect($summary['payroll'])->toBe(1)
            ->and(PayrollRun::first()->period->toDateString())->toBe('2026-04-01')
            ->and(PayrollRun::first()->prepared_actor)->toBe('autopilot')
            ->and(TaxReport::query()->pluck('type')->map->value->sort()->values()->all())->toContain('vat', 'payroll')
            ->and(TaxReport::query()->where('status', '!=', 'prepared')->count())->toBe(0)
            ->and(AiProposal::query()->where('source', 'rules')->count())->toBe(1);
    });

    it('asks the owner at level 2, and respects a cancellation', function () {
        CompanySetting::query()->update(['autopilot_level' => AutopilotLevel::Ask->value]);
        Mandate::create(['report_types' => ['vat'], 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'amount_limit_minor' => 1_000_000_000, 'expires_at' => '2027-01-01', 'status' => 'active', 'signed_name' => 'Owner', 'signed_at' => now()]);
        $owner = User::factory()->role(Role::Owner)->create();
        bookDocument('out', 1_000_000, 1200, '2026-04-10');

        app(AutopilotRunner::class)->run();
        $vat = TaxReport::query()->where('type', 'vat')->whereDate('period_start', '2026-04-01')->first();

        expect($vat->status)->toBe(TaxReportStatus::PendingApproval)
            ->and($owner->notifications()->where('data', 'like', '%approval_requested%')->exists())->toBeTrue();

        app(SubmissionOrchestrator::class)->cancel($vat, $owner);
        app(AutopilotRunner::class)->run();

        expect($vat->fresh()->status)->toBe(TaxReportStatus::Prepared)->and($vat->fresh()->autopilot_hold)->toBeTrue();
    });

    it('runs for every company from the command', function () {
        $this->artisan('bugalter:autopilot')->expectsOutputToContain('default')->assertSuccessful();
        $this->artisan('bugalter:daily')->assertSuccessful();
    });
});

describe('daily reminders', function () {
    it('warns before an ERI key expires and drops level 3 to level 1 when it has expired', function () {
        $owner = User::factory()->role(Role::Owner)->create();
        CompanySetting::query()->update(['autopilot_level' => AutopilotLevel::Auto->value]);
        Mandate::create(['report_types' => ['vat'], 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'amount_limit_minor' => 1, 'expires_at' => '2027-01-01', 'status' => 'active', 'signed_name' => 'Owner', 'signed_at' => now()]);
        app(KeyVault::class)->store('key-bytes', 'pw', ['label' => 'Main', 'owner_name' => 'Owner', 'owner_tin' => null, 'serial_number' => null, 'valid_from' => null, 'valid_to' => '2026-05-20'], $owner);

        app(DailyReminders::class)->run();
        app(DailyReminders::class)->run(); // the same threshold is not repeated

        expect($owner->notifications()->where('data', 'like', '%key_expiring%')->count())->toBe(1)
            ->and(SigningKey::first()->expiry_warnings_sent)->toBe([30, 14]);

        Carbon::setTestNow('2026-05-21');
        app(CurrentCompany::class)->forget();
        app(DailyReminders::class)->run();

        expect(SigningKey::first()->status)->toBe('expired')
            ->and(CompanySetting::first()->autopilot_level)->toBe(AutopilotLevel::Prepare);
    });
});

describe('owner controls', function () {
    it('needs a signed mandate for levels 2 and 3, and a key for level 3 with server signing', function () {
        $owner = signIn(Role::Owner);
        $settings = ['cancel_window_hours' => 12, 'signer_mode' => 'vault', 'auto_apply_classification' => '1', 'auto_apply_min_confidence' => 95];

        $this->put(route('autopilot.update'), ['autopilot_level' => '2'] + $settings)->assertSessionHasErrors('autopilot_level');

        $mandate = ['report_types' => ['vat', 'payroll'], 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'amount_limit' => '50 000 000', 'expires_at' => '2027-05-01', 'confirm' => '1'];
        post(route('autopilot.mandates.store'), $mandate + ['signed_name' => 'Someone Else'])->assertSessionHasErrors('signed_name');
        post(route('autopilot.mandates.store'), $mandate + ['signed_name' => $owner->name])->assertRedirect();
        expect(Mandate::first()->amount_limit_minor)->toBe(5_000_000_000);

        $this->put(route('autopilot.update'), ['autopilot_level' => '2'] + $settings)->assertSessionDoesntHaveErrors();
        expect(CompanySetting::first()->autopilot_level)->toBe(AutopilotLevel::Ask)
            ->and(CompanySetting::first()->cancel_window_hours)->toBe(12)
            ->and(CompanySetting::first()->autoApply(AiProposal::CLASSIFY_TRANSACTION))->toBe(['enabled' => true, 'min_confidence' => 95]);

        $this->put(route('autopilot.update'), ['autopilot_level' => '3'] + $settings)->assertSessionHasErrors('autopilot_level');

        post(route('signing-keys.store'), [
            'key_file' => UploadedFile::fake()->createWithContent('owner.pfx', random_bytes(700)),
            'password' => 'pfx-password', 'label' => 'Main', 'owner_name' => $owner->name, 'valid_to' => '2027-01-01',
        ])->assertRedirect();

        expect(SigningKey::first()->encrypted_password)->not->toContain('pfx-password');
        $this->put(route('autopilot.update'), ['autopilot_level' => '3'] + $settings)->assertSessionDoesntHaveErrors();
        expect(CompanySetting::first()->autopilot_level)->toBe(AutopilotLevel::Auto);

        // Revoking the only mandate drops the autopilot back to level 1.
        post(route('autopilot.mandates.revoke', Mandate::first()))->assertRedirect();
        expect(CompanySetting::first()->autopilot_level)->toBe(AutopilotLevel::Prepare);

        get(route('autopilot.show'))->assertOk()->assertSee(__('autopilot.mandate_statuses.revoked'));
        get(route('signing-keys.index'))->assertOk()->assertSee('Main')->assertDontSee('pfx-password');
    });

    it('keeps the autopilot and the keys to the owner', function () {
        signIn(Role::ChiefAccountant);

        get(route('autopilot.show'))->assertForbidden();
        get(route('signing-keys.index'))->assertForbidden();
        post(route('autopilot.kill-switch'))->assertForbidden();
    });
});
