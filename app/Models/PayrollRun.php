<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'period', 'status', 'gross_minor', 'pit_minor', 'inps_minor', 'social_minor', 'net_minor', 'rules',
    'prepared_actor', 'prepared_by_name', 'approved_by', 'approved_at', 'paid_transaction_id', 'paid_at',
])]
class PayrollRun extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'period' => 'date',
        'status' => PayrollStatus::class,
        'gross_minor' => 'integer',
        'pit_minor' => 'integer',
        'inps_minor' => 'integer',
        'social_minor' => 'integer',
        'net_minor' => 'integer',
        'rules' => 'array',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /** @return HasMany<PayrollLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class)->orderBy('employee_name');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === PayrollStatus::Draft;
    }

    /** PIT that goes to the budget: PIT minus the INPS part. */
    public function pitToBudgetMinor(): int
    {
        return $this->pit_minor - $this->inps_minor;
    }

    public function periodLabel(): string
    {
        return __('ui.months_full.'.$this->period->month).' '.$this->period->year;
    }
}
