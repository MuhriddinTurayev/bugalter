<?php

namespace App\Enums;

/**
 * prepared -> pending_approval -> scheduled (cancel window) -> submitting -> submitted -> accepted | rejected.
 * blocked: a hard-stop gate failed. awaiting_signature: the owner signs on their own device.
 * unknown: the signed report left the application but no reliable answer came back. It is never sent again
 * until SubmissionVerifier (or a person, after looking in the cabinet) settles what the authority holds.
 */
enum TaxReportStatus: string
{
    case Prepared = 'prepared';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case AwaitingSignature = 'awaiting_signature';
    case Blocked = 'blocked';
    case Submitting = 'submitting';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('tax.statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Prepared => 'neutral',
            self::PendingApproval, self::AwaitingSignature, self::Unknown => 'warning',
            self::Scheduled, self::Submitting, self::Submitted => 'info',
            self::Accepted => 'success',
            self::Blocked, self::Rejected => 'danger',
        };
    }

    /**
     * Once a signed report may have left the application, the calculation is frozen: it is never recalculated
     * over and never sent again, whatever the outcome turns out to be.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Submitting, self::Submitted, self::Accepted, self::Unknown], true);
    }

    /** Sent, but the authority's final answer is not known yet: SubmissionVerifier looks again. */
    public function awaitsVerification(): bool
    {
        return in_array($this, [self::Submitted, self::Unknown], true);
    }
}
