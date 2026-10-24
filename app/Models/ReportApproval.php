<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The record of one approval: who (a person or a mandate) approved which version of which report, for which
 * period and amount, and when it ended. Only invalidation may change a row; it is never deleted.
 */
#[Fillable([
    'tax_report_id', 'batch', 'source', 'user_id', 'user_name', 'mandate_id', 'version_hash', 'period_start',
    'period_end', 'payable_minor', 'company_tin', 'declaration', 'ip_address', 'user_agent', 'approved_at',
    'invalidated_at', 'invalidation_reason',
])]
class ReportApproval extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'payable_minor' => 'integer',
        'declaration' => 'boolean',
        'approved_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $approval) {
            if (array_diff(array_keys($approval->getDirty()), ['invalidated_at', 'invalidation_reason', 'updated_at']) !== []) {
                throw new LogicException('An approval record can only be invalidated, not changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Approval records are never deleted.'));
    }

    /** @return BelongsTo<TaxReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(TaxReport::class, 'tax_report_id');
    }

    /** Ends every live approval of a report (its figures changed, it was cancelled, or delivery must be redone). */
    public static function invalidateFor(TaxReport $report, string $reason): void
    {
        static::query()
            ->where('tax_report_id', $report->id)
            ->whereNull('invalidated_at')
            ->get()
            ->each(fn (self $approval) => $approval->update(['invalidated_at' => now(), 'invalidation_reason' => mb_substr($reason, 0, 255)]));
    }
}
