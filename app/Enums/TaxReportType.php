<?php

namespace App\Enums;

enum TaxReportType: string
{
    case Vat = 'vat';
    case Turnover = 'turnover';
    case Profit = 'profit';
    case Payroll = 'payroll';

    public function label(): string
    {
        return __('tax.types.'.$this->value);
    }
}
