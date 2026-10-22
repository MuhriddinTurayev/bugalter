<?php

namespace App\Enums;

enum DocumentType: string
{
    case Invoice = 'invoice';
    case Act = 'act';

    public function label(): string
    {
        return __('ui.documents.types.'.$this->value);
    }

    public function numberPrefix(): string
    {
        return match ($this) {
            self::Invoice => 'INV',
            self::Act => 'ACT',
        };
    }
}
