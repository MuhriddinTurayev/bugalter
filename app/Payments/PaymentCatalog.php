<?php

namespace App\Payments;

/**
 * What the cabinet's payment form offers an individual entrepreneur (seen on 2026-10-24): the tax types and the payment types.
 * Nothing here is a rate or an amount: these are the cabinet's own codes.
 */
final class PaymentCatalog
{
    /** Tax code => bugalter category */
    public const TAXES = ['38' => 'social', '47' => 'fixed', '100' => 'turnover', '199' => 'other'];

    /** Payment type codes the platform offers (the cabinet lists more, such as 08104 after a desk audit). */
    public const KINDS = ['08101', '08102', '08103', '08105', '08108'];

    /** The tax codes as strings (PHP turns numeric array keys into integers). @return list<string> */
    public static function taxCodes(): array
    {
        return array_map('strval', array_keys(self::TAXES));
    }

    /** The largest amount the cabinet accepts: "less than 1 trillion so'm". */
    public const MAX_MINOR = 1_000_000_000_000 * 100 - 1;
}
