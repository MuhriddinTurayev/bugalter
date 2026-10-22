<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case ChiefAccountant = 'chief_accountant';
    case Cashier = 'cashier';

    public function label(): string
    {
        return __('ui.roles.'.$this->value);
    }

    /** Owner and chief accountant: the roles that manage data, not just enter it. */
    public function isManager(): bool
    {
        return $this !== self::Cashier;
    }

    public function canViewReports(): bool
    {
        return $this->isManager();
    }

    public function canManageSettings(): bool
    {
        return $this->isManager();
    }

    public function canManageUsers(): bool
    {
        return $this === self::Owner;
    }
}
