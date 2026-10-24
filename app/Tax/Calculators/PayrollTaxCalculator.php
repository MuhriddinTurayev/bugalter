<?php

namespace App\Tax\Calculators;

use App\Enums\TaxReportType;
use App\Models\CompanySetting;
use App\Tax\Issues;
use App\Tax\Ledger;
use App\Tax\TaxComputation;
use App\Tax\TaxPeriod;

/**
 * The monthly PIT and social tax report, built from the approved payroll of the month. Every line is
 * recalculated with the rules in force; a payroll whose stored figures differ is reported, not trusted.
 */
class PayrollTaxCalculator
{
    public function __construct(private readonly PayrollCalculator $payroll, private readonly Ledger $ledger) {}

    public function compute(TaxPeriod $period, CompanySetting $company): TaxComputation
    {
        $budget = (bool) $company->is_budget_org;
        [$pitRule, $inpsRule, $socialRule] = $this->payroll->rules($period->end, $budget);

        $issues = new Issues;
        if (! $period->isClosed()) {
            $issues->error('period_open');
        }

        $sum = ['gross' => 0, 'pit' => 0, 'inps' => 0, 'social' => 0, 'net' => 0];
        $employees = 0;

        foreach ($period->months() as $month) {
            $run = $this->ledger->payrollFor($month->start);

            if ($run === null) {
                $issues->error('payroll_not_approved', 0, ['month' => $month->label()]);

                continue;
            }

            $mismatch = false;
            foreach ($run->lines as $line) {
                $expected = $this->payroll->line($line->gross_minor, $period->end, $budget);
                $mismatch = $mismatch
                    || $expected['pit'] !== $line->pit_minor
                    || $expected['inps'] !== $line->inps_minor
                    || $expected['social'] !== $line->social_minor;

                foreach ($sum as $key => $value) {
                    $sum[$key] = $value + $expected[$key];
                }

                $employees++;

                if ($line->employee !== null && $line->employee->pinfl === null) {
                    $issues->error('employee_without_pinfl', 0, ['name' => $line->employee_name]);
                }
            }

            if ($mismatch) {
                $issues->error('payroll_mismatch', 0, ['month' => $month->label()]);
            }
        }

        $payable = $sum['pit'] + $sum['social'];

        return new TaxComputation(
            type: TaxReportType::Payroll,
            period: $period,
            baseMinor: $sum['gross'],
            taxMinor: $payable,
            payableMinor: $payable,
            lines: [
                ['key' => 'payroll_gross', 'amount_minor' => $sum['gross'], 'params' => ['count' => $employees]],
                ['key' => 'pit', 'amount_minor' => $sum['pit'], 'params' => ['rate' => $pitRule->valueLabel()]],
                ['key' => 'inps', 'amount_minor' => $sum['inps'], 'params' => ['rate' => $inpsRule->valueLabel()]],
                ['key' => 'pit_to_budget', 'amount_minor' => $sum['pit'] - $sum['inps']],
                ['key' => 'social_tax', 'amount_minor' => $sum['social'], 'params' => ['rate' => $socialRule->valueLabel()]],
                ['key' => 'payroll_net', 'amount_minor' => $sum['net']],
                ['key' => 'payable', 'amount_minor' => $payable, 'total' => true],
            ],
            rules: [$pitRule->reference(), $inpsRule->reference(), $socialRule->reference()],
            issues: $issues->all(),
        );
    }
}
