<?php

namespace App\Enums;

enum ContractorType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';

    public function label(): string
    {
        return __('ui.contractors.types.'.$this->value);
    }
}
