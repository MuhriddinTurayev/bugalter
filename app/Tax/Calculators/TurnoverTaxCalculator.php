<?php

namespace App\Tax\Calculators;

use App\Enums\TaxRegime;
use App\Enums\TaxReportType;
use App\Models\CompanySetting;
use App\Tax\Issues;
use App\Tax\Ledger;
use App\Tax\Percent;
use App\Tax\TaxComputation;
use App\Tax\TaxPeriod;
use App\Tax\TaxRuleBook;

/** Turnover tax for a quarter: revenue (outgoing documents without VAT + retail income) × rate. */
class TurnoverTaxCalculator
{
    public function __construct(private readonly TaxRuleBook $rules, private readonly Ledger $ledger) {}

    public function compute(TaxPeriod $period, CompanySetting $company): TaxComputation
    {
        $rate = $this->rules->rule('turnover.general', $period->end);
        $threshold = $this->rules->rule('vat.registration_threshold', $period->end);

        $out = $this->ledger->documents('out', $period->start, $period->end);
        $retail = $this->ledger->retailIncome($period->start, $period->end);

        $base = $out['subtotal'] + $retail;
        $tax = Percent::of($base, $rate->value);

        $issues = (new Issues)->bookkeeping($this->ledger, $period);

        if ($company->regime() !== TaxRegime::Turnover) {
            $issues->warning('regime_mismatch');
        }

        if ($out['vat'] > 0) {
            $issues->warning('vat_on_turnover_regime');
        }

        // Revenue from the start of the year: over the threshold, the company must move to VAT.
        $yearOut = $this->ledger->documents('out', $period->yearStart(), $period->end);
        $yearRevenue = $yearOut['subtotal'] + $this->ledger->retailIncome($period->yearStart(), $period->end);
        if ($yearRevenue > $threshold->value) {
            $issues->warning('over_vat_threshold', 0, ['threshold' => $threshold->valueLabel()]);
        }

        return new TaxComputation(
            type: TaxReportType::Turnover,
            period: $period,
            baseMinor: $base,
            taxMinor: $tax,
            payableMinor: $tax,
            lines: [
                ['key' => 'sales_documents', 'amount_minor' => $out['subtotal'], 'params' => ['count' => $out['count']]],
                ['key' => 'retail_income', 'amount_minor' => $retail],
                ['key' => 'turnover_base', 'amount_minor' => $base, 'total' => true],
                ['key' => 'turnover_tax', 'amount_minor' => $tax, 'params' => ['rate' => $rate->valueLabel()]],
                ['key' => 'payable', 'amount_minor' => $tax, 'total' => true],
            ],
            rules: [$rate->reference(), $threshold->reference()],
            issues: $issues->all(),
        );
    }
}
