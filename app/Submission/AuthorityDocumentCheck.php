<?php

namespace App\Submission;

use App\Models\CompanySetting;
use App\Models\TaxReport;
use App\Portal\AuthorityDocument;

/**
 * Before the portal's own document is signed: does it say exactly what was approved? Taxpayer, report type,
 * period and every amount must match the approved report; anything missing counts as a mismatch.
 *
 * The values come from the robot reading the filled form. Once each cabinet form is mapped, a parser of the
 * document bytes themselves should feed this check instead.
 */
final class AuthorityDocumentCheck
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @return list<string> the fields that do not match (empty: the document may be signed) */
    public static function mismatches(TaxReport $report, CompanySetting $company, AuthorityDocument $document): array
    {
        $expected = [
            'tin' => (string) ($company->stir ?: $company->pinfl),
            'type' => $report->type->value,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'base_minor' => $report->base_minor,
            'tax_minor' => $report->tax_minor,
            'payable_minor' => $report->payable_minor,
        ];

        $mismatches = [];

        foreach ($expected as $field => $value) {
            $actual = $document->fields[$field] ?? null;

            $same = is_int($value)
                ? (is_int($actual) || (is_string($actual) && preg_match('/^-?\d+$/', $actual))) && (int) $actual === $value
                : is_string($actual) && $actual === $value;

            if (! $same) {
                $mismatches[] = $field;
            }
        }

        if ($document->content === '' || strlen($document->content) > self::MAX_BYTES) {
            $mismatches[] = 'content';
        }

        return $mismatches;
    }
}
