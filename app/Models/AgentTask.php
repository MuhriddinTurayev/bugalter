<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One job for the desktop agent. Types:
 *   portal_check   sign in to the cabinet and read it (reports, balances, messages)
 *   submit_report  sign in, fill the form of one approved report, let Laravel compare the filled form with the
 *                  approved figures, then sign (the accountant confirms in E-IMZO) and send, and read the answer
 *
 * Statuses: queued -> claimed -> (needs_user) -> completed | failed | cancelled.
 */
#[Fillable([
    'type', 'status', 'desktop_agent_id', 'tax_report_id', 'tax_payment_id', 'submission_attempt_id', 'portal_check_id', 'version_hash',
    'payload', 'events', 'result', 'error', 'waiting_for', 'actor_type', 'actor_name', 'claimed_at', 'heartbeat_at',
    'lease_expires_at', 'finished_at',
])]
class AgentTask extends Model
{
    public const OPEN = ['queued', 'claimed', 'needs_user'];

    /** A claimed task whose agent has not reported for this long is considered lost. */
    public const LEASE_MINUTES = 5;

    /** The most recent events kept on a task. */
    public const MAX_EVENTS = 60;

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'events' => 'array',
        'result' => 'array',
        'claimed_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<TaxReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(TaxReport::class, 'tax_report_id');
    }

    /** @return BelongsTo<DesktopAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(DesktopAgent::class, 'desktop_agent_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Claimed and still reporting: an attempt it holds must not be declared interrupted. */
    public function isAlive(): bool
    {
        return in_array($this->status, ['claimed', 'needs_user'], true) && $this->lease_expires_at !== null && $this->lease_expires_at->isFuture();
    }

    public function tone(): string
    {
        return match ($this->status) {
            'completed' => 'success',
            'failed' => 'danger',
            'needs_user' => 'warning',
            'cancelled' => 'neutral',
            default => 'info',
        };
    }
}
