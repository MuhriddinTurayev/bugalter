<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One "prepare reports automatically" run (App\Tax\ReportWorkflow): the selected month, the real state of each
 * step as it ran, and the reports it found. Statuses: queued, running, completed, failed.
 */
#[Fillable(['status', 'period_month', 'steps', 'results', 'error', 'actor_type', 'actor_name', 'user_id', 'started_at', 'finished_at'])]
class ReportRun extends Model
{
    public const STEPS = ['profile', 'portal', 'obligations', 'data', 'prepare', 'validate'];

    /** A run that has not finished within this many minutes is treated as dead (the job timed out). */
    public const STALE_MINUTES = 20;

    /** @var array<string, string> */
    protected $casts = [
        'period_month' => 'immutable_date',
        'steps' => 'array',
        'results' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public static function activeRun(): ?self
    {
        return static::query()
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>', now()->subMinutes(self::STALE_MINUTES))
            ->latest('id')
            ->first();
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true)
            && $this->created_at !== null && $this->created_at->isAfter(now()->subMinutes(self::STALE_MINUTES));
    }

    public function month(): CarbonImmutable
    {
        return $this->period_month->startOfMonth();
    }

    /** @return list<array{key: string, status: string, params: array<string, mixed>}> every step, pending ones included */
    public function stepList(): array
    {
        $stored = collect($this->steps ?? [])->keyBy('key');

        return array_map(fn (string $key) => $stored[$key] ?? ['key' => $key, 'status' => 'pending', 'params' => []], self::STEPS);
    }
}
