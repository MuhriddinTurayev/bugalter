<?php

use App\Support\Money;

it('parses user input into integer minor units without floats', function (?string $input, ?int $expected) {
    expect(Money::parseToMinor($input))->toBe($expected);
})->with([
    ['1500000', 150_000_000],
    ['1 500 000,50', 150_000_050],
    ["1\u{00A0}500\u{00A0}000.5", 150_000_050],
    ['0,01', 1],
    ['19.99', 1999],
    ['', null],
    ['abc', null],
    ['-5', null],
    ['1,234', null],
    ['1.2.3', null],
    [null, null],
]);

it('formats minor units for display', function () {
    app()->setLocale('uz');

    expect(Money::format(123_456_789))->toBe("1\u{00A0}234\u{00A0}567,89\u{00A0}soʻm")
        ->and(Money::format(150_000_000, false))->toBe("1\u{00A0}500\u{00A0}000")
        ->and(Money::format(-5000))->toBe("−50\u{00A0}soʻm")
        ->and(Money::format(0, false))->toBe('0');
});

it('round-trips minor units through the form string', function () {
    expect(Money::toInputString(150_050))->toBe('1500.50')
        ->and(Money::toInputString(5))->toBe('0.05')
        ->and(Money::parseToMinor(Money::toInputString(987_654_321)))->toBe(987_654_321);
});

it('formats compact amounts for charts', function () {
    app()->setLocale('uz');

    expect(Money::compact(150_000_000_000))->toContain('mlrd')
        ->and(Money::compact(250_000_000))->toContain('mln');
});
