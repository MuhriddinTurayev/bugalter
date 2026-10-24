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

/**
 * VAT for a month: output VAT (outgoing documents + VAT contained in retail sales) minus input VAT
 * (incoming documents). A negative result is a credit; nothing is payable then.
 */
class VatCalculator
{
    public function __construct(private readonly TaxRuleBook $rules, private readonly Ledger $ledger) {}

    public function compute(TaxPeriod $period, CompanySetting $company): TaxComputation
    {
        $standard = $this->rules->rule('vat.standard', $period->end);
        $zero = $this->rules->rule('vat.zero', $period->end);

        $out = $this->ledger->documents('out', $period->start, $period->end);
        $in = $this->ledger->documents('in', $period->start, $period->end);

        // "Other income" (interest, fines received) carries no VAT; sales and services do.
        $retail = $this->ledger->retailIncome($period->start, $period->end, ['other_income']);
        $retailVat = Percent::containedIn($retail, $standard->value);

        $output = $out['vat'] + $retailVat;
        $input = $in['vat'];
        $net = $output - $input;
        $payable = max(0, $net);

        $lines = [
            ['key' => 'sales_documents', 'amount_minor' => $out['subtotal'], 'params' => ['count' => $out['count']]],
            ['key' => 'output_vat_documents', 'amount_minor' => $out['vat']],
            ['key' => 'retail_sales', 'amount_minor' => $retail],
            ['key' => 'output_vat_retail', 'amount_minor' => $retailVat, 'params' => ['rate' => $standard->valueLabel()]],
            ['key' => 'output_vat_total', 'amount_minor' => $output, 'total' => true],
            ['key' => 'input_vat_documents', 'amount_minor' => $input, 'params' => ['count' => $in['count']]],
        ];

        if ($net < 0) {
            $lines[] = ['key' => 'vat_credit', 'amount_minor' => -$net];
        }

        $lines[] = ['key' => 'payable', 'amount_minor' => $payable, 'total' => true];

        $issues = (new Issues)->bookkeeping($this->ledger, $period);

        if (! $company->vat_payer) {
            $issues->error('not_vat_payer');
        }

        $unknownRates = array_values(array_diff($this->ledger->vatRatesUsed($period->start, $period->end), [$standard->value, $zero->value]));
        if ($unknownRates !== []) {
            $issues->error('vat_rate_unknown', count($unknownRates), [
                'rates' => implode(', ', array_map(fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',').'%', $unknownRates)),
            ]);
        }

        $issues->countError('documents_without_tin', $this->ledger->documentsWithoutContractorTin($period->start, $period->end));

        return new TaxComputation(
            type: TaxReportType::Vat,
            period: $period,
            baseMinor: $out['subtotal'] + ($retail - $retailVat),
            taxMinor: $output,
            payableMinor: $payable,
            lines: $lines,
            rules: [$standard->reference(), $zero->reference()],
            issues: $issues->all(),
        );
    }
}
