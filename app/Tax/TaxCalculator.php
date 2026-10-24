<?php

namespace App\Tax;

use App\Enums\TaxReportType;
use App\Models\CompanySetting;
use App\Tax\Calculators\PayrollTaxCalculator;
use App\Tax\Calculators\ProfitTaxCalculator;
use App\Tax\Calculators\TurnoverTaxCalculator;
use App\Tax\Calculators\VatCalculator;

/** The single entry point to the deterministic calculators. The AI only ever calls this. */
class TaxCalculator
{
    public function __construct(
        private readonly VatCalculator $vat,
        private readonly TurnoverTaxCalculator $turnover,
        private readonly ProfitTaxCalculator $profit,
        private readonly PayrollTaxCalculator $payroll,
    ) {}

    public function compute(TaxReportType $type, TaxPeriod $period, CompanySetting $company): TaxComputation
    {
        return match ($type) {
            TaxReportType::Vat => $this->vat->compute($period, $company),
            TaxReportType::Turnover => $this->turnover->compute($period, $company),
            TaxReportType::Profit => $this->profit->compute($period, $company),
            TaxReportType::Payroll => $this->payroll->compute($period, $company),
        };
    }
}
