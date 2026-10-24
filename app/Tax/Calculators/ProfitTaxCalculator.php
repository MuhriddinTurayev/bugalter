<?php

namespace App\Tax\Calculators;

use App\Enums\TaxReportType;
use App\Models\CompanySetting;
use App\Tax\Issues;
use App\Tax\Ledger;
use App\Tax\Percent;
use App\Tax\TaxComputation;
use App\Tax\TaxPeriod;
use App\Tax\TaxRuleBook;
use Carbon\CarbonImmutable;

/**
 * Profit tax, year to date. Payable for the quarter = tax on the profit from 1 January to the end of the
 * quarter minus tax on the profit to the end of the previous quarter. Revenue and costs exclude VAT.
 */
class ProfitTaxCalculator
{
    public function __construct(private readonly TaxRuleBook $rules, private readonly Ledger $ledger) {}

    public function compute(TaxPeriod $period, CompanySetting $company): TaxComputation
    {
        $rate = $this->rules->rule('profit.general', $period->end);
        $vat = $company->vat_payer ? $this->rules->rule('vat.standard', $period->end) : null;
        $vatRate = $vat === null ? 0 : $vat->value; // a non-VAT payer's retail income contains no VAT

        $ytd = $this->profit($period->yearStart(), $period->end, $vatRate);
        $previous = $period->start->isSameDay($period->yearStart())
            ? null
            : $this->profit($period->yearStart(), $period->start->subDay(), $vatRate);

        $taxYtd = Percent::of(max(0, $ytd['profit']), $rate->value);
        $taxPrevious = $previous === null ? 0 : Percent::of(max(0, $previous['profit']), $rate->value);
        $difference = $taxYtd - $taxPrevious;
        $payable = max(0, $difference);

        $lines = [
            ['key' => 'revenue_ytd', 'amount_minor' => $ytd['revenue']],
            ['key' => 'expenses_documents_ytd', 'amount_minor' => $ytd['documents']],
            ['key' => 'expenses_other_ytd', 'amount_minor' => $ytd['other']],
            ['key' => 'payroll_cost_ytd', 'amount_minor' => $ytd['payroll']],
            ['key' => $ytd['profit'] < 0 ? 'loss_ytd' : 'profit_ytd', 'amount_minor' => abs($ytd['profit']), 'total' => true],
            ['key' => 'profit_tax_ytd', 'amount_minor' => $taxYtd, 'params' => ['rate' => $rate->valueLabel()]],
            ['key' => 'profit_tax_previous', 'amount_minor' => $taxPrevious],
        ];

        if ($difference < 0) {
            $lines[] = ['key' => 'profit_tax_credit', 'amount_minor' => -$difference];
        }

        $lines[] = ['key' => 'payable', 'amount_minor' => $payable, 'total' => true];

        $issues = (new Issues)->bookkeeping($this->ledger, $period);

        foreach ($period->months() as $month) {
            if ($month->isClosed() && $this->ledger->payrollFor($month->start) === null && $this->ledger->salaryPayments($month->start, $month->end) > 0) {
                $issues->warning('payroll_missing_for_month', 0, ['month' => $month->label()]);
            }
        }

        $rules = [$rate->reference()];
        if ($vat !== null) {
            $rules[] = $vat->reference();
        }

        return new TaxComputation(
            type: TaxReportType::Profit,
            period: $period,
            baseMinor: max(0, $ytd['profit']),
            taxMinor: $taxYtd,
            payableMinor: $payable,
            lines: $lines,
            rules: $rules,
            issues: $issues->all(),
        );
    }

    /** @return array{revenue: int, documents: int, other: int, payroll: int, profit: int} */
    private function profit(CarbonImmutable $from, CarbonImmutable $to, int $vatRate): array
    {
        $out = $this->ledger->documents('out', $from, $to);
        $in = $this->ledger->documents('in', $from, $to);

        $retailAll = $this->ledger->retailIncome($from, $to);
        $retailVat = Percent::containedIn($this->ledger->retailIncome($from, $to, ['other_income']), $vatRate);

        $revenue = $out['subtotal'] + $retailAll - $retailVat;
        $other = $this->ledger->undocumentedExpenses($from, $to, withSalaries: false);
        $payroll = $this->payrollCost($from, $to);

        return [
            'revenue' => $revenue,
            'documents' => $in['subtotal'],
            'other' => $other,
            'payroll' => $payroll,
            'profit' => $revenue - $in['subtotal'] - $other - $payroll,
        ];
    }

    /** Gross pay and social tax of approved payrolls; for a month without one, the salary payments booked. */
    private function payrollCost(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $total = 0;

        for ($month = $from->startOfMonth(); $month <= $to; $month = $month->addMonth()) {
            $run = $this->ledger->payrollFor($month);

            $total += $run !== null
                ? $run->gross_minor + $run->social_minor
                : $this->ledger->salaryPayments($month->max($from), $month->endOfMonth()->min($to));
        }

        return $total;
    }
}
