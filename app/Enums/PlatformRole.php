<?php

namespace App\Enums;

enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case Support = 'support';

    public function label(): string
    {
        return __('platform.roles.'.$this->value);
    }
}
