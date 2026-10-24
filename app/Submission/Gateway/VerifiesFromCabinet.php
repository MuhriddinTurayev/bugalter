<?php

namespace App\Submission\Gateway;

use App\Models\PortalCheck;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;

/**
 * Settles a delivery from what the tax cabinet showed at a visit that began after it. A report missing from the
 * cabinet's list is not proof that it never arrived (the list may lag), so absence stays "unknown".
 */
trait VerifiesFromCabinet
{
    protected function verifyFromCabinet(TaxReport $report, SubmissionAttempt $attempt): GatewayResult
    {
        $check = PortalCheck::latestCompleted();

        if ($check === null || $check->started_at->isBefore($attempt->started_at)) {
            return new GatewayResult('unknown', errors: [__('tax.portal.errors.verify_needs_check')]);
        }

        $row = $check->reportRow($report->type, $report->period_start->toDateString());
        $raw = ['portal_check' => $check->id];

        return match ($row['status'] ?? null) {
            'accepted' => new GatewayResult('accepted', $row['receipt'] ?? null, raw: $raw),
            'submitted' => new GatewayResult('submitted', $row['receipt'] ?? null, raw: $raw),
            'rejected' => new GatewayResult('rejected', $row['receipt'] ?? null, [__('tax.portal.errors.rejected_on_portal')], $raw),
            default => new GatewayResult('unknown', errors: [__('tax.portal.errors.not_on_portal_yet')], raw: $raw),
        };
    }

    /** The cabinet must have been read, must be this taxpayer's, and must not already hold this report. */
    protected function cabinetErrors(TaxReport $report): array
    {
        $check = PortalCheck::latestCompleted();

        return match (true) {
            $check === null => [__('tax.portal.errors.not_checked')],
            isset($check->summary['tin_mismatch']) => [__('tax.portal.errors.tin_mismatch')],
            $check->showsSubmitted($report->type, $report->period_start->toDateString()) => [__('tax.portal.errors.already_on_portal')],
            default => [],
        };
    }
}
