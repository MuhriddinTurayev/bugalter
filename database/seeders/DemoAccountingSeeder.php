<?php

namespace Database\Seeders;

use App\Ai\ClassifyTransactions;
use App\Enums\Actor;
use App\Enums\AutopilotLevel;
use App\Enums\PayrollStatus;
use App\Enums\Role;
use App\Enums\TaxReportType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AiProposal;
use App\Models\Contractor;
use App\Models\Mandate;
use App\Models\TaxReport;
use App\Models\Transaction;
use App\Models\User;
use App\Payroll\PayrollService;
use App\Services\CurrentCompany;
use App\Submission\Signing\KeyVault;
use App\Submission\SubmissionOrchestrator;
use App\Tax\TaxPeriod;
use App\Tax\TaxReportPreparer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * DEMO only, run after DemoSeeder inside a company: payrolls, tax reports in every interesting state, a mandate,
 * a sandbox signing key, the autopilot level and unclassified bank lines with proposals. Everything goes through
 * the real services, so the demo shows exactly what the application does.
 */
class DemoAccountingSeeder extends Seeder
{
    private int $variant = 0;

    public function forVariant(int $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function run(
        CurrentCompany $company,
        PayrollService $payroll,
        TaxReportPreparer $preparer,
        SubmissionOrchestrator $orchestrator,
        KeyVault $vault,
        ClassifyTransactions $classify,
    ): void {
        $owner = User::query()->where('role', Role::Owner)->orderBy('id')->firstOrFail();
        $chief = User::query()->where('role', Role::ChiefAccountant)->first() ?? $owner;
        $today = CarbonImmutable::today();
        $first = $this->variant === 0;
        // A demo key goes into the vault only where the vault may fall back to a derived master key.
        $withKey = $first && (filled(config('signing.master_key')) || app()->environment('local', 'testing'));

        $company->forget();
        $settings = $company->settings();

        // The first demo company runs at level 2 under a mandate with a key in the vault; the second signs on
        // the owner's device and stays at level 1.
        if ($first) {
            Mandate::create([
                'report_types' => [TaxReportType::Vat->value, TaxReportType::Payroll->value],
                'period_from' => $today->startOfYear()->toDateString(),
                'period_to' => $today->endOfYear()->toDateString(),
                'amount_limit_minor' => 50_000_000 * 100,
                'change_limit_percent' => 50,
                'expires_at' => $today->addYear()->toDateString(),
                'status' => 'active',
                'signed_name' => $owner->name,
                'signed_at' => now(),
                'created_by' => $owner->id,
            ]);

        }

        if ($withKey) {
            // Random bytes stand in for a .pfx file: with the sandbox signer this proves the vault round trip.
            $vault->store(random_bytes(1536), 'demo-key-password', [
                'label' => 'DEMO kalit (sandbox)',
                'owner_name' => $owner->name,
                'owner_tin' => $settings->stir,
                'serial_number' => 'DEMO-7D1E-0001',
                'valid_from' => $today->subYear()->toDateString(),
                'valid_to' => $today->addDays(20)->toDateString(), // close to expiry, so the warning is visible
            ], $owner);
        }

        $settings->forceFill([
            'tax_regime' => 'general',
            'autopilot_level' => $first ? AutopilotLevel::Ask : AutopilotLevel::Prepare,
            'cancel_window_hours' => 24,
            'signer_mode' => $withKey ? 'vault' : 'device',
            'ai_auto_apply' => [AiProposal::CLASSIFY_TRANSACTION => ['enabled' => $first, 'min_confidence' => 90]],
        ])->save();
        $company->forget();

        $this->as($chief, function () use ($payroll, $today, $chief) {
            foreach ([3, 2, 1] as $back) {
                $month = $today->startOfMonth()->subMonths($back);
                $run = $payroll->prepare($month, Actor::User, $chief->name);
                $payroll->approve($run, $chief);

                // The demo already books the salary payments; older months are simply marked paid.
                if ($back > 1) {
                    $run->update(['status' => PayrollStatus::Paid, 'paid_at' => $month->addMonth()->day(5)->setTime(10, 0)]);
                }
            }
        });

        $lastMonth = TaxPeriod::containing('month', $today->subMonth());
        $monthBefore = $lastMonth->previous();
        $lastQuarter = TaxPeriod::containing('quarter', $today->startOfQuarter()->subDay());

        $this->as($owner, function () use ($preparer, $orchestrator, $owner, $monthBefore) {
            // Two months ago: approved by the owner and submitted through the sandbox gateway.
            foreach ([TaxReportType::Vat, TaxReportType::Payroll] as $type) {
                $report = $preparer->prepare($type, $monthBefore, Actor::User, $owner->name);
                $orchestrator->approveByUser($report, $owner);
            }
        });

        if ($withKey) {
            // Server signing: submit right away (the owner ends the cancel window).
            $this->as($owner, function () use ($orchestrator, $owner, $monthBefore) {
                foreach (TaxReport::query()->whereDate('period_start', $monthBefore->start->toDateString())->get() as $report) {
                    $orchestrator->submitNow($report, $owner);
                }
            });
        }

        // Last month: prepared by the autopilot; the VAT report asks the owner for approval.
        $vat = $preparer->prepare(TaxReportType::Vat, $lastMonth, Actor::Autopilot);
        $preparer->prepare(TaxReportType::Payroll, $lastMonth, Actor::Autopilot);

        if ($vat->blockingIssues() === []) {
            $orchestrator->requestSubmission($vat, Actor::Autopilot);
        }

        // Last quarter's profit tax: approved by the owner and waiting out the cancel window.
        $profit = $preparer->prepare(TaxReportType::Profit, $lastQuarter, Actor::Autopilot);
        if ($profit->blockingIssues() === []) {
            $this->as($owner, fn () => $orchestrator->approveByUser($profit, $owner));
        }

        $this->bankLinesToClassify($today);
        $classify(withAi: false);
    }

    /** Lines as they come from a bank statement: no category yet. They are in the current month only. */
    private function bankLinesToClassify(CarbonImmutable $today): void
    {
        $bank = Account::query()->where('type', 'bank')->first();
        $supplier = Contractor::query()->where('type', 'supplier')->where('is_active', true)->orderBy('id')->first();
        $creator = User::query()->where('role', Role::Cashier)->value('id');

        $lines = [
            [TransactionType::Expense, 'Ofis ijarasi', 12_000_000, null],
            [TransactionType::Expense, 'Kommunal toʻlovlar', 2_450_000, null],
            [TransactionType::Expense, 'Bank xizmati uchun komissiya', 85_000, null],
            [TransactionType::Expense, 'Toʻlov: shartnoma boʻyicha', 3_600_000, $supplier?->id],
            [TransactionType::Income, 'Click orqali tushum', 1_750_000, null],
        ];

        foreach ($lines as $i => [$type, $description, $som, $contractor]) {
            $when = $today->startOfMonth()->addDays(min($i + 1, max(0, $today->day - 1)))->setTime(11, 30);

            Transaction::create([
                'account_id' => $bank->id,
                'contractor_id' => $contractor,
                'type' => $type,
                'amount_minor' => $som * 100,
                'occurred_at' => $when->isFuture() ? now()->subHour() : $when,
                'description' => $description,
                'reference' => 'BANK-'.$today->format('ym').'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'status' => 'completed',
                'created_by' => $creator,
            ]);
        }
    }

    /** Runs a step as a person, so the audit log names them like in real use. */
    private function as(User $user, callable $callback): mixed
    {
        Auth::guard('web')->setUser($user);

        try {
            return $callback();
        } finally {
            Auth::guard('web')->forgetUser();
        }
    }
}
