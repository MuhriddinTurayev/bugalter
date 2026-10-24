<?php

namespace App\Enums;

enum PayrollStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';

    public function label(): string
    {
        return __('payroll.statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Approved => 'info',
            self::Paid => 'success',
        };
    }
}
