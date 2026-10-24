<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A tax payment the owner approved in the platform, made by the desktop agent in the tax cabinet. */
#[Fillable([
    'client_token', 'tax_code', 'kind', 'amount_minor', 'purpose', 'dry_run', 'status', 'version_hash', 'idempotency_key', 'approved_by',
    'approved_by_name', 'approved_at', 'attempt_started_at', 'order_number', 'order_date', 'result', 'error',
])]
class TaxPayment extends Model
{
    /** Still in progress: nothing else for the same tax and amount may be started from the same form. */
    public const OPEN = ['queued', 'sending'];

    /** @var array<string, string> */
    protected $casts = [
        'amount_minor' => 'integer',
        'dry_run' => 'boolean',
        'approved_at' => 'datetime',
        'attempt_started_at' => 'datetime',
        'order_date' => 'date',
        'result' => 'array',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** The outcome is not known: it is never retried by itself, only settled by what the cabinet's list shows. */
    public function isUnsettled(): bool
    {
        return in_array($this->status, ['sending', 'unknown', 'submitted'], true);
    }

    public function tone(): string
    {
        return match ($this->status) {
            'paid' => 'success',
            'failed' => 'danger',
            'unknown', 'submitted' => 'warning',
            'cancelled', 'checked' => 'neutral',
            default => 'info',
        };
    }
}
