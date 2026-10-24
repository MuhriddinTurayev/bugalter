<?php

namespace App\Submission\Gateway;

use App\Models\CompanySetting;
use App\Models\TaxReport;

/** Format checks every gateway applies before anything is signed. */
trait BasicValidation
{
    /**
     * A demo company's figures are sample data: they must never reach a real tax authority. Only the real channels
     * call this; the sandbox gateway still accepts demo companies.
     *
     * @return list<string>
     */
    protected function demoErrors(CompanySetting $company): array
    {
        return $company->is_demo ? [__('tax.gateway_errors.demo_company')] : [];
    }

    /** @return list<string> */
    protected function basicErrors(TaxReport $report, CompanySetting $company): array
    {
        $errors = [];

        // A company is identified by its STIR (9 digits); an individual entrepreneur by the PINFL (14) of the E-IMZO key.
        if (! preg_match('/^(\d{9}|\d{14})$/', (string) ($company->stir ?: $company->pinfl))) {
            $errors[] = __('tax.gateway_errors.tin');
        }

        if (! $report->period_end->isBefore(today())) {
            $errors[] = __('tax.gateway_errors.period_open');
        }

        if ($report->payable_minor < 0 || $report->tax_minor < 0 || $report->base_minor < 0) {
            $errors[] = __('tax.gateway_errors.negative');
        }

        if (empty($report->lines)) {
            $errors[] = __('tax.gateway_errors.empty');
        }

        if ($report->type->value === 'vat' && ! $company->vat_payer) {
            $errors[] = __('tax.gateway_errors.not_vat_payer');
        }

        return $errors;
    }
}
