<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Failed = 'failed';

    public function label(): string
    {
        return __('platform.statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Provisioning => 'info',
            self::Suspended => 'warning',
            self::Failed => 'danger',
        };
    }
}
