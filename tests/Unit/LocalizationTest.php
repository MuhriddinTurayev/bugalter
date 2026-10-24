<?php

/** @return array<string, mixed> */
function flattenLang(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        is_array($value)
            ? $flat += flattenLang($value, $prefix.$key.'.')
            : $flat[$prefix.$key] = $value;
    }

    return $flat;
}

it('has the same translation keys in Uzbek and Russian', function (string $file) {
    $uz = array_keys(flattenLang(require lang_path("uz/$file.php")));
    $ru = array_keys(flattenLang(require lang_path("ru/$file.php")));

    expect(array_diff($uz, $ru))->toBe([])->and(array_diff($ru, $uz))->toBe([]);
})->with(['ui', 'platform', 'app', 'auth', 'validation', 'passwords', 'pagination', 'tax', 'ai', 'payroll', 'autopilot']);

it('uses the same placeholders in both languages', function (string $file) {
    $uz = flattenLang(require lang_path("uz/$file.php"));
    $ru = flattenLang(require lang_path("ru/$file.php"));

    foreach ($uz as $key => $text) {
        preg_match_all('/:[a-z_]+/i', (string) $text, $a);
        preg_match_all('/:[a-z_]+/i', (string) $ru[$key], $b);

        expect(collect($a[0])->map(fn ($p) => strtolower($p))->sort()->values()->all())->toBe(collect($b[0])->map(fn ($p) => strtolower($p))->sort()->values()->all(), "placeholders differ for $file.$key");
    }
})->with(['ui', 'platform', 'tax', 'ai', 'payroll', 'autopilot']);

it('defaults to Uzbek', function () {
    expect(config('app.locale'))->toBe('uz')->and(config('app.fallback_locale'))->toBe('uz');
});
