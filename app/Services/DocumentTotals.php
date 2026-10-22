<?php

namespace App\Services;

use App\Support\Money as AppMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Deterministic document arithmetic on integer minor units.
 * Each line is rounded to whole tiyin (half up); the document totals are sums of rounded lines.
 */
class DocumentTotals
{
    /**
     * @param  string  $quantity  decimal string with up to 3 decimals, e.g. "2.500"
     * @param  int  $vatRateBp  VAT rate in basis points (1200 = 12%), entered by the user
     * @return array{subtotal: int, vat: int, total: int}
     */
    public function line(string $quantity, int $unitPriceMinor, int $vatRateBp): array
    {
        $price = Money::ofMinor($unitPriceMinor, AppMoney::CURRENCY);

        $subtotal = $price->multipliedBy($quantity, RoundingMode::HalfUp);
        $vat = $subtotal->multipliedBy(BigDecimal::ofUnscaledValue($vatRateBp, 4), RoundingMode::HalfUp);

        $subtotalMinor = $subtotal->getMinorAmount()->toInt();
        $vatMinor = $vat->getMinorAmount()->toInt();

        return [
            'subtotal' => $subtotalMinor,
            'vat' => $vatMinor,
            'total' => $subtotalMinor + $vatMinor,
        ];
    }

    /**
     * @param  iterable<array{quantity: string, unit_price_minor: int, vat_rate_bp: int}>  $lines
     * @return array{subtotal: int, vat: int, total: int}
     */
    public function forLines(iterable $lines): array
    {
        $sum = ['subtotal' => 0, 'vat' => 0, 'total' => 0];

        foreach ($lines as $line) {
            $result = $this->line((string) $line['quantity'], (int) $line['unit_price_minor'], (int) $line['vat_rate_bp']);

            foreach ($sum as $key => $value) {
                $sum[$key] = $value + $result[$key];
            }
        }

        return $sum;
    }
}
