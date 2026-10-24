<?php

namespace App\Portal;

use App\Enums\TaxReportType;
use App\Tax\CabinetReport;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalises what the robot read from the cabinet into one fixed shape. The robot is outside this application,
 * so nothing it returns is trusted: unknown keys are dropped, money must be integer tiyin, dates Y-m-d,
 * statuses and tax codes come from fixed lists, and text is cut to length. Portal text is data for display
 * (and for the AI as quoted data), never instructions.
 *
 *   taxpayer: {tin, name, vat_payer, regime}
 *   balances: [{tax, name, debt_minor, overpaid_minor, penalty_minor, assessed_minor, total_minor, code, as_of}]
 *   reports:  [{type, form, name, period_start, period_end, status, submitted_at, receipt}]
 *   messages: [{date, title, unread}]
 *   payments: [{number, date, tax_code, tax, amount_minor, status_text, paid, payer_bank_code, payer_account, receiver_account, year}]
 *   drafts:   [{type, form, period_start, period_end, due_date, rate_bp, lines: {code: minor}}]  (what the cabinet calculated for an unfiled period)
 */
final class PortalSnapshot
{
    public const REPORT_STATUSES = ['accepted', 'submitted', 'rejected', 'draft'];

    public const TAXES = ['vat', 'turnover', 'profit', 'pit', 'social', 'property', 'land', 'water', 'fixed', 'other'];

    private const MAX_ROWS = 300;

    /** @param  array<string, mixed>  $raw */
    public static function normalize(array $raw): array
    {
        $taxpayer = is_array($raw['taxpayer'] ?? null) ? $raw['taxpayer'] : [];

        return [
            'taxpayer' => [
                'tin' => self::tin($taxpayer['tin'] ?? null),
                'name' => self::text($taxpayer['name'] ?? null),
                'vat_payer' => is_bool($taxpayer['vat_payer'] ?? null) ? $taxpayer['vat_payer'] : null,
                'regime' => in_array($taxpayer['regime'] ?? null, ['general', 'turnover'], true) ? $taxpayer['regime'] : null,
            ],
            'balances' => self::rows($raw['balances'] ?? [], fn (array $row) => [
                'tax' => in_array($row['tax'] ?? null, self::TAXES, true) ? $row['tax'] : 'other',
                'name' => self::text($row['name'] ?? null) ?? '',
                'debt_minor' => self::money($row['debt_minor'] ?? 0),
                'overpaid_minor' => self::money($row['overpaid_minor'] ?? 0),
                'penalty_minor' => self::money($row['penalty_minor'] ?? 0),
                'assessed_minor' => self::money($row['assessed_minor'] ?? 0),
                'total_minor' => self::money($row['total_minor'] ?? 0),
                'code' => self::digits($row['code'] ?? null, 4),
                'as_of' => self::date($row['as_of'] ?? null),
            ]),
            'reports' => self::rows($raw['reports'] ?? [], fn (array $row) => [
                'type' => TaxReportType::tryFrom((string) ($row['type'] ?? ''))?->value,
                'form' => self::text($row['form'] ?? null, 40) ?? '',
                'name' => self::text($row['name'] ?? null) ?? '',
                'period_start' => self::date($row['period_start'] ?? null),
                'period_end' => self::date($row['period_end'] ?? null),
                'status' => in_array($row['status'] ?? null, self::REPORT_STATUSES, true) ? $row['status'] : 'submitted',
                'submitted_at' => self::date($row['submitted_at'] ?? null),
                'receipt' => self::text($row['receipt'] ?? null, 80),
            ]),
            'payments' => self::rows($raw['payments'] ?? [], fn (array $row) => [
                'number' => self::digits($row['number'] ?? null, 12),
                'date' => self::date($row['date'] ?? null),
                'tax_code' => self::digits($row['tax_code'] ?? null, 4),
                'tax' => in_array($row['tax'] ?? null, self::TAXES, true) ? $row['tax'] : 'other',
                'amount_minor' => self::money($row['amount_minor'] ?? 0),
                'status_text' => self::text($row['status_text'] ?? null, 160) ?? '',
                'paid' => (bool) ($row['paid'] ?? false),
                'payer_bank_code' => self::text($row['payer_bank_code'] ?? null, 12),
                'payer_account' => self::digits($row['payer_account'] ?? null, 20),
                'receiver_account' => self::digits($row['receiver_account'] ?? null, 20),
                'year' => preg_match('/^\d{4}$/', (string) ($row['year'] ?? '')) ? (string) $row['year'] : null,
            ]),
            'drafts' => self::rows($raw['drafts'] ?? [], fn (array $row) => [
                'type' => TaxReportType::tryFrom((string) ($row['type'] ?? ''))?->value,
                'form' => self::text($row['form'] ?? null, 40) ?? '',
                'period_start' => self::date($row['period_start'] ?? null),
                'period_end' => self::date($row['period_end'] ?? null),
                'due_date' => self::date($row['due_date'] ?? null),
                'rate_bp' => min(10_000, self::money($row['rate_bp'] ?? 0)),
                'lines' => self::lines($row['lines'] ?? []),
            ]),
            'messages' => self::rows($raw['messages'] ?? [], fn (array $row) => [
                'date' => self::date($row['date'] ?? null),
                'title' => self::text($row['title'] ?? null) ?? '',
                'unread' => (bool) ($row['unread'] ?? false),
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows, callable $map): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_ROWS) as $row) {
            if (is_array($row)) {
                $out[] = $map($row);
            }
        }

        return $out;
    }

    /** @return array<string, int> only the form's own line codes, each an integer amount of tiyin */
    private static function lines(mixed $lines): array
    {
        $out = [];

        foreach (is_array($lines) ? $lines : [] as $code => $amount) {
            if (array_key_exists((string) $code, CabinetReport::CODES)) {
                $out[(string) $code] = self::money($amount);
            }
        }

        return $out;
    }

    private static function digits(mixed $value, int $max): ?string
    {
        $digits = substr(preg_replace('/\D/', '', (string) (is_scalar($value) ? $value : '')) ?? '', 0, $max);

        return $digits === '' ? null : $digits;
    }

    private static function money(mixed $value): int
    {
        // Integers only: a float or a formatted string from the portal is rejected rather than rounded.
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && preg_match('/^\d{1,18}$/', $value)) {
            return (int) $value;
        }

        throw new PortalException(__('tax.portal.errors.bad_data'));
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private static function tin(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return preg_match('/^\d{9}$|^\d{14}$/', $digits) ? $digits : null;
    }

    private static function text(mixed $value, int $max = 255): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
