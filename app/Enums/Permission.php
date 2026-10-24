<?php

namespace App\Enums;

/**
 * Fine-grained company permissions (PLAN section 3). A role gives a default set; the owner can grant
 * more to a person. The AI actor holds none of them.
 */
enum Permission: string
{
    case ReportsApprove = 'reports.approve';
    case ReportsSubmit = 'reports.submit';
    case SigningKeysManage = 'signing.keys.manage';
    case AutopilotManage = 'autopilot.manage';
    case PayrollApprove = 'payroll.approve';

    public function label(): string
    {
        return __('ui.permissions.'.str_replace('.', '_', $this->value));
    }

    /** @return list<self> */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::Owner => self::cases(),
            Role::ChiefAccountant => [self::ReportsSubmit, self::PayrollApprove],
            Role::Cashier => [],
        };
    }
}
