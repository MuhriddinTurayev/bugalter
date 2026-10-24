<?php

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Employee;
use App\Models\TaxRule;
use App\Models\User;
use App\Payroll\PayrollService;
use App\Services\CurrentCompany;
use App\Submission\SubmissionRefused;
use App\Tax\Calculators\PayrollCalculator;
use App\Tax\MissingTaxRule;
use App\Tax\Percent;
use App\Tax\TaxCalculator;
use App\Tax\TaxComputation;
use App\Tax\TaxObligations;
use App\Tax\TaxPeriod;
use App\Tax\TaxReportPreparer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-05-10 10:00:00');
    seedTaxRules();
    CompanySetting::create(['name' => 'Test MChJ', 'stir' => '300000009', 'vat_payer' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function taxFor(TaxReportType $type, TaxPeriod $period): TaxComputation
{
    app(CurrentCompany::class)->forget();

    return app(TaxCalculator::class)->compute($type, $period, app(CurrentCompany::class)->settings());
}

function taxLine(TaxComputation $computation, string $key): ?int
{
    return collect($computation->lines)->firstWhere('key', $key)['amount_minor'] ?? null;
}

function issueKeys(TaxComputation $computation, ?string $severity = null): array
{
    return collect($computation->issues)->when($severity, fn ($c) => $c->where('severity', $severity))->pluck('key')->all();
}

describe('VAT', function () {
    it('takes output VAT from sales and retail, input VAT from purchases, and nothing from drafts, cancellations or other months', function () {
        bookDocument('out', 1_000_000, 1200, '2026-04-10');                                 // VAT 120 000
        bookDocument('in', 500_000, 1200, '2026-04-12');                                    // input VAT 60 000
        bookDocument('out', 999_999, 1200, '2026-04-20', DocumentStatus::Cancelled);        // ignored
        bookDocument('out', 999_999, 1200, '2026-05-02');                                   // next month
        bookTransaction('income', 112_000, '2026-04-15', 'sales');                          // retail: contains 12 000 VAT
        bookTransaction('income', 50_000, '2026-04-16', 'sales', ['contractor_id' => Contractor::factory()->create()->id]); // settles an invoice

        $vat = taxFor(TaxReportType::Vat, TaxPeriod::month(2026, 4));

        expect(taxLine($vat, 'output_vat_documents'))->toBe(12_000_000)
            ->and(taxLine($vat, 'output_vat_retail'))->toBe(1_200_000)
            ->and(taxLine($vat, 'output_vat_total'))->toBe(13_200_000)
            ->and(taxLine($vat, 'input_vat_documents'))->toBe(6_000_000)
            ->and($vat->payableMinor)->toBe(7_200_000)
            ->and(issueKeys($vat, 'error'))->toBe([])
            ->and(issueKeys($vat, 'warning'))->toBe(['unlinked_contractor_payments']);
    });

    it('shows a credit and nothing payable when input VAT is larger', function () {
        bookDocument('out', 100_000, 1200, '2026-04-10');
        bookDocument('in', 300_000, 1200, '2026-04-11');

        $vat = taxFor(TaxReportType::Vat, TaxPeriod::month(2026, 4));

        expect($vat->payableMinor)->toBe(0)->and(taxLine($vat, 'vat_credit'))->toBe(2_400_000);
    });

    it('blocks on unclassified transactions, drafts, unknown rates and an open period', function () {
        bookTransaction('expense', 10_000, '2026-04-03', null);
        bookDocument('out', 10_000, 1200, '2026-04-05', DocumentStatus::Draft);
        bookDocument('out', 10_000, 1500, '2026-04-06');

        expect(issueKeys(taxFor(TaxReportType::Vat, TaxPeriod::month(2026, 4)), 'error'))
            ->toContain('unclassified_transactions', 'draft_documents', 'vat_rate_unknown')
            ->and(issueKeys(taxFor(TaxReportType::Vat, TaxPeriod::month(2026, 5)), 'error'))->toContain('period_open');
    });

    it('never guesses a missing rule', function () {
        TaxRule::query()->where('code', 'vat.standard')->delete();

        taxFor(TaxReportType::Vat, TaxPeriod::month(2026, 4));
    })->throws(MissingTaxRule::class);
});

it('charges turnover tax on the quarter revenue', function () {
    CompanySetting::query()->update(['tax_regime' => 'turnover', 'vat_payer' => false]);
    bookDocument('out', 2_000_000, 0, '2026-02-10');
    bookTransaction('income', 50_000, '2026-03-01', 'sales');

    $tax = taxFor(TaxReportType::Turnover, TaxPeriod::quarter(2026, 1));

    expect(taxLine($tax, 'turnover_base'))->toBe(205_000_000)
        ->and($tax->payableMinor)->toBe(8_200_000) // 4%
        ->and(issueKeys($tax))->toBe([]);
});

it('calculates profit tax year to date and charges only the difference for the quarter', function () {
    Carbon::setTestNow('2026-07-10');

    bookDocument('out', 1_000_000, 1200, '2026-01-20');
    bookDocument('in', 400_000, 1200, '2026-02-10');
    bookTransaction('expense', 100_000, '2026-03-05', 'rent');
    bookTransaction('expense', 50_000, '2026-04-20', 'taxes_fees');  // a tax payment is not a cost
    bookDocument('out', 600_000, 1200, '2026-05-15');
    bookTransaction('expense', 200_000, '2026-06-25', 'salaries');   // no approved payroll: the payment counts

    $q1 = taxFor(TaxReportType::Profit, TaxPeriod::quarter(2026, 1));
    $q2 = taxFor(TaxReportType::Profit, TaxPeriod::quarter(2026, 2));

    // Q1 profit 500 000 → 75 000; H1 profit 900 000 → 135 000; Q2 pays 60 000.
    expect($q1->payableMinor)->toBe(7_500_000)
        ->and(taxLine($q2, 'profit_ytd'))->toBe(90_000_000)
        ->and(taxLine($q2, 'profit_tax_previous'))->toBe(7_500_000)
        ->and($q2->payableMinor)->toBe(6_000_000)
        ->and(issueKeys($q2, 'warning'))->toContain('payroll_missing_for_month');
});

describe('payroll', function () {
    it('withholds PIT with INPS inside it and adds social tax on top', function () {
        $calculator = app(PayrollCalculator::class);
        $on = CarbonImmutable::parse('2026-04-30');

        expect($calculator->line(500_000_000, $on, false))->toBe(['gross' => 500_000_000, 'pit' => 60_000_000, 'inps' => 500_000, 'social' => 60_000_000, 'net' => 440_000_000])
            ->and($calculator->line(500_000_000, $on, true)['social'])->toBe(125_000_000);
    });

    it('builds the PIT and social tax report only from an approved payroll', function () {
        Employee::create(['full_name' => 'Ali Valiyev', 'pinfl' => '31234567890123', 'status' => 'active', 'salary_minor' => 400_000_000]);

        expect(issueKeys(taxFor(TaxReportType::Payroll, TaxPeriod::month(2026, 4)), 'error'))->toContain('payroll_not_approved');

        $chief = User::factory()->role(Role::ChiefAccountant)->create();
        $service = app(PayrollService::class);
        $service->approve($service->prepare(CarbonImmutable::parse('2026-04-01')), $chief);

        $report = taxFor(TaxReportType::Payroll, TaxPeriod::month(2026, 4));

        expect(issueKeys($report, 'error'))->toBe([])
            ->and(taxLine($report, 'pit'))->toBe(48_000_000)
            ->and(taxLine($report, 'inps'))->toBe(400_000)
            ->and(taxLine($report, 'pit_to_budget'))->toBe(47_600_000)
            ->and(taxLine($report, 'social_tax'))->toBe(48_000_000)
            ->and($report->payableMinor)->toBe(96_000_000);
    });
});

it('rounds every rate half up on whole tiyin', function () {
    expect(Percent::of(333, 1250))->toBe(42)
        ->and(Percent::of(-333, 1250))->toBe(-42)
        ->and(Percent::containedIn(11_200, 1200))->toBe(1_200)
        ->and(Percent::containedIn(100, 1200))->toBe(11)
        ->and(Percent::containedIn(100, 0))->toBe(0);
});

it('voids an approval when the figures change and never recalculates a submitted report', function () {
    bookDocument('out', 100_000, 1200, '2026-04-10');
    $preparer = app(TaxReportPreparer::class);
    $report = $preparer->prepare(TaxReportType::Vat, TaxPeriod::month(2026, 4));

    $report->update(['status' => TaxReportStatus::Scheduled, 'approval_source' => 'user', 'approved_version_hash' => $report->version_hash, 'approved_at' => now(), 'scheduled_at' => now()->addDay()]);

    // Same figures: the approval stays.
    expect($preparer->prepare(TaxReportType::Vat, TaxPeriod::month(2026, 4))->status)->toBe(TaxReportStatus::Scheduled);

    bookDocument('out', 50_000, 1200, '2026-04-11');
    $report = $preparer->prepare(TaxReportType::Vat, TaxPeriod::month(2026, 4));

    expect($report->status)->toBe(TaxReportStatus::Prepared)
        ->and($report->approved_version_hash)->toBeNull()
        ->and($report->scheduled_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'tax_report.approval_voided')->exists())->toBeTrue();

    $report->update(['status' => TaxReportStatus::Accepted]);

    expect(fn () => $preparer->prepare(TaxReportType::Vat, TaxPeriod::month(2026, 4)))->toThrow(SubmissionRefused::class);
});

it('builds the tax calendar from the regime and the deadline rules', function () {
    Employee::create(['full_name' => 'Ali Valiyev', 'pinfl' => '31234567890123', 'status' => 'active', 'salary_minor' => 500_000_00, 'hired_on' => '2025-01-01']);
    $rows = collect(app(TaxObligations::class)->calendar(CompanySetting::first(), 1))
        ->mapWithKeys(fn ($row) => [$row['type']->value.'@'.$row['period']->start->toDateString() => $row['due_date']->toDateString()]);

    expect($rows->get('vat@2026-04-01'))->toBe('2026-05-20')
        ->and($rows->get('payroll@2026-04-01'))->toBe('2026-05-15')
        ->and($rows->get('profit@2026-01-01'))->toBe('2026-04-20')
        ->and($rows->keys()->filter(fn ($k) => str_starts_with($k, 'turnover'))->all())->toBe([]);
});

it('expects no payroll return while nobody is employed', function () {
    $keys = collect(app(TaxObligations::class)->calendar(CompanySetting::first(), 1))->map(fn ($row) => $row['type']->value)->unique()->all();

    expect($keys)->not->toContain('payroll')->and($keys)->toContain('vat');
});
