<?php

use App\Services\DocumentTotals;

it('computes a line from quantity, price and VAT in minor units', function () {
    // 2.5 x 1 000,50 so'm = 2 501,25 so'm; 12% VAT = 300,15 so'm.
    $line = (new DocumentTotals)->line('2.500', 100_050, 1200);

    expect($line)->toBe(['subtotal' => 250_125, 'vat' => 30_015, 'total' => 280_140]);
});

it('rounds half up to whole tiyin', function () {
    $totals = new DocumentTotals;

    // 0.5 tiyin rounds up to 1.
    expect($totals->line('0.500', 1, 0)['subtotal'])->toBe(1)
        // 333,33 so'm x 3 = 999,99; VAT 12% = 119,9988 -> 120,00.
        ->and($totals->line('3.000', 33_333, 1200))->toBe(['subtotal' => 99_999, 'vat' => 12_000, 'total' => 111_999]);
});

it('supports zero VAT and fractional quantities', function () {
    expect((new DocumentTotals)->line('0.001', 1_000_000_000, 0))->toBe(['subtotal' => 1_000_000, 'vat' => 0, 'total' => 1_000_000]);
});

it('sums rounded lines instead of rounding the sum', function () {
    $sum = (new DocumentTotals)->forLines([
        ['quantity' => '1', 'unit_price_minor' => 10_005, 'vat_rate_bp' => 1200],
        ['quantity' => '1', 'unit_price_minor' => 10_005, 'vat_rate_bp' => 1200],
    ]);

    // Each line: VAT 1200.6 -> 1201; two lines -> 2402 (not 2401).
    expect($sum)->toBe(['subtotal' => 20_010, 'vat' => 2_402, 'total' => 22_412]);
});

it('handles very large amounts exactly', function () {
    // 999 999 999 999 so'm x 1 000 units, no float precision loss.
    $line = (new DocumentTotals)->line('1000.000', 99_999_999_999_900, 0);

    expect($line['subtotal'])->toBe(99_999_999_999_900_000);
});
