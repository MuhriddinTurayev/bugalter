<?php

namespace App\Submission;

use App\Models\CompanySetting;
use App\Models\TaxReport;

/** The canonical content of a report as it is signed and sent. Its hash is what the signer signs. */
final class ReportPayload
{
    /** @return array<string, mixed> */
    public static function for(TaxReport $report, CompanySetting $company): array
    {
        return [
            'format' => 'bugalter.tax-report.v1',
            'taxpayer' => [
                'tin' => $company->stir,
                'name' => $company->name,
                'vat_code' => $company->vat_payer ? $company->vat_registration_code : null,
            ],
            'report' => [
                'type' => $report->type->value,
                'period_start' => $report->period_start->toDateString(),
                'period_end' => $report->period_end->toDateString(),
                'currency' => 'UZS',
                'base_minor' => $report->base_minor,
                'tax_minor' => $report->tax_minor,
                'payable_minor' => $report->payable_minor,
                'lines' => array_map(fn (array $line) => ['key' => $line['key'], 'amount_minor' => $line['amount_minor']], $report->lines ?? []),
            ],
            'rules' => array_map(fn (array $rule) => ['code' => $rule['code'], 'version' => $rule['version'] ?? null], $report->rules ?? []),
            'version_hash' => $report->version_hash,
        ];
    }

    public static function hash(TaxReport $report, CompanySetting $company): string
    {
        return hash('sha256', json_encode(self::for($report, $company), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
