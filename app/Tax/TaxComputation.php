<?php

namespace App\Tax;

use App\Enums\TaxReportType;

/**
 * The output of a deterministic calculator. Lines are labelled by translation keys (tax.lines.*),
 * issues by tax.issues.*; rules lists every rule version used, so a report can be re-checked later.
 */
final class TaxComputation
{
    /**
     * @param  list<array{key: string, amount_minor: int, params?: array<string, scalar>, total?: bool}>  $lines
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array{key: string, severity: string, count?: int, params?: array<string, scalar>}>  $issues
     */
    public function __construct(
        public readonly TaxReportType $type,
        public readonly TaxPeriod $period,
        public readonly int $baseMinor,
        public readonly int $taxMinor,
        public readonly int $payableMinor,
        public readonly array $lines,
        public readonly array $rules,
        public readonly array $issues,
    ) {}

    /** Changes whenever any figure, line or rule version changes. Issues do not change the figures. */
    public function versionHash(): string
    {
        return hash('sha256', json_encode([
            $this->type->value,
            $this->period->start->toDateString(),
            $this->period->end->toDateString(),
            $this->baseMinor,
            $this->taxMinor,
            $this->payableMinor,
            $this->lines,
            array_map(fn (array $r) => [$r['code'], $r['value'] ?? null, $r['version'] ?? null], $this->rules),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'period_start' => $this->period->start->toDateString(),
            'period_end' => $this->period->end->toDateString(),
            'base_minor' => $this->baseMinor,
            'tax_minor' => $this->taxMinor,
            'payable_minor' => $this->payableMinor,
            'lines' => $this->lines,
            'rules' => $this->rules,
            'issues' => $this->issues,
        ];
    }
}
