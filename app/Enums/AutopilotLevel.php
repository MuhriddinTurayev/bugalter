<?php

namespace App\Enums;

/** PLAN section 5, "Autopilot levels". */
enum AutopilotLevel: int
{
    case Off = 0;
    case Prepare = 1;
    case Ask = 2;
    case Auto = 3;

    public function label(): string
    {
        return __('autopilot.levels.'.$this->value.'.name');
    }

    public function description(): string
    {
        return __('autopilot.levels.'.$this->value.'.text');
    }

    /** Levels 2 and 3 require an active mandate. */
    public function needsMandate(): bool
    {
        return $this === self::Ask || $this === self::Auto;
    }
}
