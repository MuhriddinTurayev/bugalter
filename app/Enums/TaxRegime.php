<?php

namespace App\Enums;

enum TaxRegime: string
{
    /** VAT (when registered) and profit tax. */
    case General = 'general';

    /** Turnover tax instead of VAT and profit tax. */
    case Turnover = 'turnover';

    public function label(): string
    {
        return __('tax.regimes.'.$this->value);
    }

    /** @return list<TaxReportType> */
    public function reportTypes(bool $vatPayer): array
    {
        return match ($this) {
            self::General => array_values(array_filter([
                $vatPayer ? TaxReportType::Vat : null,
                TaxReportType::Profit,
                TaxReportType::Payroll,
            ])),
            self::Turnover => [TaxReportType::Turnover, TaxReportType::Payroll],
        };
    }
}
