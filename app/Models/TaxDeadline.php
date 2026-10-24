<?php

namespace App\Models;

use App\Enums\TaxReportType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * When a report is due: `day` of the month that is `months_after` months after the period ends.
 *
 * @property string $report_type
 * @property string $period month or quarter
 */
#[Fillable(['report_type', 'period', 'months_after', 'day', 'effective_from', 'effective_to', 'source', 'status', 'verified_by', 'verified_at', 'version'])]
class TaxDeadline extends Model
{
    use CentralConnection;

    /** @var array<string, string> */
    protected $casts = [
        'months_after' => 'integer',
        'day' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'verified_at' => 'datetime',
        'version' => 'integer',
    ];

    public function isVerified(): bool
    {
        return $this->status === TaxRule::VERIFIED;
    }

    public function type(): TaxReportType
    {
        return TaxReportType::from($this->report_type);
    }

    public function dueDateFor(CarbonImmutable $periodEnd): CarbonImmutable
    {
        $month = $periodEnd->startOfMonth()->addMonthsNoOverflow($this->months_after);

        return $month->day(min($this->day, $month->daysInMonth));
    }

    public function reference(): array
    {
        return [
            'code' => 'deadline.'.$this->report_type.'.'.$this->period,
            'months_after' => $this->months_after,
            'day' => $this->day,
            'version' => $this->version,
            'verified' => $this->isVerified(),
        ];
    }
}
