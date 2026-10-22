<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Money helpers. Amounts are integers in minor units (tiyin); floats are never used.
 */
final class Money
{
    public const CURRENCY = 'UZS';

    private const NBSP = "\u{00A0}";

    /** Format minor units for display, e.g. 123456789 => "1 234 567,89 soʻm". */
    public static function format(int $minor, bool $withCurrency = true): string
    {
        $abs = abs($minor);
        $whole = intdiv($abs, 100);
        $fraction = $abs % 100;

        $text = number_format($whole, 0, '', self::NBSP);

        if ($fraction !== 0) {
            $text .= ','.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
        }

        if ($minor < 0) {
            $text = '−'.$text;
        }

        return $withCurrency ? $text.self::NBSP.__('ui.currency.uzs') : $text;
    }

    /** Compact form for chart axes and tight cards: 1 500 000 000 => "1,5 mlrd". */
    public static function compact(int $minor): string
    {
        $som = intdiv(abs($minor), 100);
        $sign = $minor < 0 ? '−' : '';

        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'] as $divider => $unit) {
            if ($som >= $divider) {
                $value = rtrim(rtrim(number_format($som / $divider, 1, ',', ''), '0'), ',');

                return $sign.$value.self::NBSP.__('ui.units.'.$unit);
            }
        }

        return $sign.(string) $som;
    }

    /**
     * Parse user input in som ("1 500 000,50") to minor units without floats.
     * Returns null when the input is not a valid non-negative amount.
     */
    public static function parseToMinor(null|string|int $input): ?int
    {
        if ($input === null) {
            return null;
        }

        $clean = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', (string) $input);
        $clean = str_replace(',', '.', (string) $clean);

        if (! preg_match('/^\d{1,15}(\.\d{1,2})?$/', $clean)) {
            return null;
        }

        try {
            return BigDecimal::of($clean)
                ->multipliedBy(100)
                ->toScale(0, RoundingMode::HalfUp)
                ->toBigInteger()
                ->toInt();
        } catch (MathException) {
            return null;
        }
    }

    /** Minor units as a plain decimal string for form fields: 150050 => "1500.50". */
    public static function toInputString(int $minor): string
    {
        $abs = abs($minor);
        $text = intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '').$text;
    }
}
