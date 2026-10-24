<?php

namespace App\Models;

use App\Enums\TaxReportType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One visit of the tax cabinet: the normalised snapshot of what the portal showed and the deterministic
 * findings (App\Portal\PortalAnalyzer). Portal text in the snapshot is data from outside, never instructions.
 */
#[Fillable(['status', 'portal', 'actor_type', 'actor_name', 'started_at', 'finished_at', 'snapshot', 'findings', 'summary', 'error'])]
class PortalCheck extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'snapshot' => 'array',
        'findings' => 'array',
        'summary' => 'array',
    ];

    public static function latestCompleted(): ?self
    {
        return static::query()->where('status', 'completed')->latest('finished_at')->latest('id')->first();
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /** The portal already shows an accepted (or pending) report of this type and period. */
    public function showsSubmitted(TaxReportType $type, string $periodStart): bool
    {
        foreach ($this->snapshot['reports'] ?? [] as $row) {
            if ($row['type'] === $type->value && $row['period_start'] === $periodStart && in_array($row['status'], ['accepted', 'submitted'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cabinet's row for a report type and period; with several filings the most final one wins.
     *
     * @return array<string, mixed>|null
     */
    public function reportRow(TaxReportType $type, string $periodStart): ?array
    {
        $rank = ['accepted' => 3, 'submitted' => 2, 'rejected' => 1, 'draft' => 0];
        $best = null;

        foreach ($this->snapshot['reports'] ?? [] as $row) {
            if ($row['type'] === $type->value && $row['period_start'] === $periodStart
                && ($best === null || ($rank[$row['status']] ?? 0) > ($rank[$best['status']] ?? 0))) {
                $best = $row;
            }
        }

        return $best;
    }

    /** @return list<array<string, mixed>> */
    public function findingsOf(string $severity): array
    {
        return array_values(array_filter($this->findings ?? [], fn (array $f) => $f['severity'] === $severity));
    }
}
