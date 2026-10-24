<?php

namespace App\Models;

use App\Enums\TaxReportStatus;
use App\Enums\TaxReportType;
use App\Tax\TaxPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tax report for one type and period. The figures come only from the deterministic calculators; the
 * version hash changes with any figure, and an approval is valid only for the version it was given for.
 */
#[Fillable([
    'type', 'source', 'period_start', 'period_end', 'due_date', 'status', 'base_minor', 'tax_minor', 'payable_minor',
    'lines', 'rules', 'issues', 'version_hash', 'prepared_actor', 'prepared_by_name', 'prepared_at',
    'approval_source', 'approved_by', 'approved_by_name', 'mandate_id', 'approved_version_hash', 'approved_at',
    'requested_at', 'requested_actor', 'scheduled_at', 'gate_results', 'signature_log_id', 'submitted_at',
    'receipt_number', 'authority_response', 'status_note', 'autopilot_hold',
])]
class TaxReport extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'type' => TaxReportType::class,
        'status' => TaxReportStatus::class,
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'base_minor' => 'integer',
        'tax_minor' => 'integer',
        'payable_minor' => 'integer',
        'lines' => 'array',
        'rules' => 'array',
        'issues' => 'array',
        'gate_results' => 'array',
        'authority_response' => 'array',
        'prepared_at' => 'datetime',
        'approved_at' => 'datetime',
        'requested_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'submitted_at' => 'datetime',
        'autopilot_hold' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'autopilot_hold' => false,
    ];

    /** @return BelongsTo<Mandate, $this> */
    public function mandate(): BelongsTo
    {
        return $this->belongsTo(Mandate::class);
    }

    /** @return BelongsTo<SignatureLog, $this> */
    public function signatureLog(): BelongsTo
    {
        return $this->belongsTo(SignatureLog::class);
    }

    public function period(): TaxPeriod
    {
        $kind = $this->period_start->isSameDay($this->period_end->copy()->startOfMonth()) ? 'month' : 'quarter';

        return TaxPeriod::fromStart($kind, $this->period_start->toImmutable());
    }

    public function periodLabel(): string
    {
        return $this->period()->label();
    }

    public function title(): string
    {
        return $this->type->label().' · '.$this->periodLabel();
    }

    /** Rules this report was calculated with that nobody has verified yet. */
    public function unverifiedRules(): array
    {
        return array_values(array_filter($this->rules ?? [], fn (array $rule) => ! ($rule['verified'] ?? false)));
    }

    /** Data-quality problems that block a submission. */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues ?? [], fn (array $issue) => ($issue['severity'] ?? 'error') === 'error'));
    }

    public function isApprovalValid(): bool
    {
        return $this->approved_version_hash !== null && hash_equals($this->approved_version_hash, $this->version_hash);
    }

    public function isOverdue(): bool
    {
        return ! $this->status->isFinal() && $this->due_date !== null && $this->due_date->isBefore(today());
    }
}
