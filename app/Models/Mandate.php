<?php

namespace App\Models;

use App\Enums\TaxReportType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The owner's written standing authorisation for autopilot submissions: report types, tax periods,
 * an amount limit and an expiry date (PLAN section 5).
 */
#[Fillable([
    'report_types', 'period_from', 'period_to', 'amount_limit_minor', 'change_limit_percent', 'expires_at',
    'status', 'signed_name', 'signed_at', 'created_by', 'revoked_at', 'revoked_by_name',
])]
class Mandate extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'report_types' => 'array',
        'period_from' => 'date',
        'period_to' => 'date',
        'expires_at' => 'date',
        'amount_limit_minor' => 'integer',
        'change_limit_percent' => 'integer',
        'signed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->expires_at->isBefore(today());
    }

    public function covers(TaxReportType $type): bool
    {
        return in_array($type->value, $this->report_types ?? [], true);
    }

    /** The mandate that would authorise this report, if any. */
    public static function activeFor(TaxReport $report): ?self
    {
        return static::query()
            ->where('status', 'active')
            ->whereDate('expires_at', '>=', today())
            ->latest('id')
            ->get()
            ->first(fn (self $mandate) => $mandate->covers($report->type)
                && ! $report->period_start->isBefore($mandate->period_from)
                && ! $report->period_end->isAfter($mandate->period_to));
    }

    public static function hasActive(): bool
    {
        return static::query()->where('status', 'active')->whereDate('expires_at', '>=', today())->exists();
    }

    public function statusLabel(): string
    {
        if ($this->status === 'active' && $this->expires_at->isBefore(today())) {
            return __('autopilot.mandate_statuses.expired');
        }

        return __('autopilot.mandate_statuses.'.$this->status);
    }
}
