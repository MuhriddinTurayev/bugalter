<?php

namespace App\Enums;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';

    public function label(): string
    {
        return __('ui.bank.account_types.'.$this->value);
    }
}
