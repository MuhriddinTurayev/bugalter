<?php

namespace App\Enums;

/** Who performed an action. Every autonomous action names one (CLAUDE.md, "AI accountant"). */
enum Actor: string
{
    case User = 'user';
    case Ai = 'ai';
    case Autopilot = 'autopilot';
    case System = 'system';
    case Platform = 'platform';

    public function label(): string
    {
        return __('ui.actors.'.$this->value);
    }
}
