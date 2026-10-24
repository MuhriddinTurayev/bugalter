<?php

namespace App\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Rate arithmetic on integer tiyin. Rates are basis points (1200 = 12%); results round half up. */
final class Percent
{
    public static function of(int $minor, int $basisPoints): int
    {
        return BigDecimal::of($minor)
            ->multipliedBy($basisPoints)
            ->dividedBy(10_000, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    /** The tax contained in a gross amount: gross × rate / (100% + rate). */
    public static function containedIn(int $grossMinor, int $basisPoints): int
    {
        if ($basisPoints === 0) {
            return 0;
        }

        $net = BigDecimal::of($grossMinor)
            ->multipliedBy(10_000)
            ->dividedBy(10_000 + $basisPoints, 0, RoundingMode::HalfUp)
            ->toInt();

        return $grossMinor - $net;
    }
}
