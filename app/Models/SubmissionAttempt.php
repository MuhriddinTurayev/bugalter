<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery of a signed report to the tax authority. Statuses:
 *   sending       written before the report leaves the application; still "sending" later means a crash
 *   submitted     the authority took it and has not decided yet
 *   accepted      the authority accepted it (receipt)
 *   rejected      the authority refused it (definite)
 *   unknown       no reliable answer: never resent until reconciled
 *   not_received  reconciliation showed the authority never got it: safe to send again with the same key
 */
#[Fillable([
    'tax_report_id', 'idempotency_key', 'version_hash', 'document_hash', 'gateway', 'status', 'receipt_number',
    'errors', 'response', 'signature_log_id', 'actor_type', 'actor_name', 'approval_ref', 'started_at',
    'finished_at', 'last_checked_at', 'verified_at', 'verified_by', 'resolution_note',
])]
class SubmissionAttempt extends Model
{
    /** Attempts whose outcome is still open: the report must not be sent again while one exists. */
    public const OPEN = ['sending', 'submitted', 'unknown'];

    /** @var array<string, string> */
    protected $casts = [
        'errors' => 'array',
        'response' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /** @return BelongsTo<TaxReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(TaxReport::class, 'tax_report_id');
    }

    /**
     * The idempotency key for the next delivery of this report's approved version. It stays the same until the
     * authority definitely rejects that version, so a resend after "not received" is recognisable as a duplicate.
     */
    public static function keyFor(TaxReport $report): string
    {
        $version = (string) ($report->approved_version_hash ?? $report->version_hash);
        $rejected = static::query()
            ->where('tax_report_id', $report->id)
            ->where('version_hash', $version)
            ->where('status', 'rejected')
            ->count();

        return hash('sha256', implode('|', [(string) tenant()?->getTenantKey(), $report->id, $version, $rejected]));
    }

    public static function openFor(TaxReport $report): ?self
    {
        return static::query()->where('tax_report_id', $report->id)->whereIn('status', self::OPEN)->latest('id')->first();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function tone(): string
    {
        return match ($this->status) {
            'accepted' => 'success',
            'rejected' => 'danger',
            'unknown', 'sending' => 'warning',
            default => 'info',
        };
    }
}
