<?php

namespace App\Tax;

use App\Enums\TaxReportStatus;
use App\Models\TaxReport;

/**
 * One status per obligation in the "prepare reports automatically" review, derived deterministically from the
 * report's state, its data-quality issues and the gateway's pre-submission validation. Never from the AI.
 */
final class ReportChecklist
{
    public const STATUSES = [
        'not_checked', 'required', 'not_required', 'data_missing', 'draft_prepared', 'validation_failed',
        'ready_for_approval', 'approved', 'in_progress', 'submitted', 'accepted', 'rejected', 'unknown',
    ];

    /**
     * @param  bool  $applies  the company's regime owes this report
     * @param  bool  $closed  the period has ended
     * @param  bool  $prepareFailed  preparing it failed (a rule or deadline is missing)
     * @param  list<string>  $validationErrors  the gateway's checks before signing
     */
    public static function status(?TaxReport $report, bool $applies, bool $closed, bool $prepareFailed = false, array $validationErrors = []): string
    {
        if (! $applies) {
            return 'not_required';
        }

        if ($report === null) {
            return match (true) {
                $prepareFailed => 'data_missing',
                $closed => 'required',
                default => 'not_checked',
            };
        }

        return match ($report->status) {
            TaxReportStatus::Scheduled, TaxReportStatus::AwaitingSignature => 'approved',
            TaxReportStatus::Submitting => 'in_progress',
            TaxReportStatus::Submitted => 'submitted',
            TaxReportStatus::Accepted => 'accepted',
            TaxReportStatus::Rejected => 'rejected',
            TaxReportStatus::Unknown => 'unknown',
            TaxReportStatus::Blocked => 'validation_failed',
            TaxReportStatus::Prepared, TaxReportStatus::PendingApproval => match (true) {
                ! $closed => 'draft_prepared',
                $report->blockingIssues() !== [] => 'data_missing',
                $validationErrors !== [] => 'validation_failed',
                default => 'ready_for_approval',
            },
        };
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            'accepted' => 'success',
            'ready_for_approval', 'approved', 'in_progress', 'submitted' => 'info',
            'data_missing', 'validation_failed', 'rejected' => 'danger',
            'unknown', 'required' => 'warning',
            default => 'neutral',
        };
    }

    /** Statuses a person may select for approval and submission in the review. */
    public static function selectable(string $status): bool
    {
        return $status === 'ready_for_approval';
    }
}
