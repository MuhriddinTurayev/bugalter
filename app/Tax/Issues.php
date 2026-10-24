<?php

namespace App\Tax;

use Carbon\CarbonImmutable;

/** Data-quality checks shared by the calculators. Errors block a submission; warnings are shown only. */
final class Issues
{
    /** @var list<array{key: string, severity: string, count?: int, params?: array<string, scalar>}> */
    private array $items = [];

    public function error(string $key, int $count = 0, array $params = []): self
    {
        return $this->add($key, 'error', $count, $params);
    }

    public function warning(string $key, int $count = 0, array $params = []): self
    {
        return $this->add($key, 'warning', $count, $params);
    }

    /** Checks every revenue-based report needs: the period is over and the books for it are complete. */
    public function bookkeeping(Ledger $ledger, TaxPeriod $period, ?CarbonImmutable $today = null): self
    {
        if (! $period->isClosed($today)) {
            $this->error('period_open');
        }

        // Nothing recorded for the period: a "0 so'm" return would be invented, not computed.
        if (! $ledger->hasRecords($period->start, $period->end)) {
            $this->error('no_books');
        }

        $this->countError('unclassified_transactions', $ledger->unclassifiedCount($period->start, $period->end));
        $this->countError('draft_documents', $ledger->draftDocumentsCount($period->start, $period->end));

        if ($count = $ledger->pendingCount($period->start, $period->end)) {
            $this->warning('pending_transactions', $count);
        }

        if ($count = $ledger->unlinkedContractorPayments($period->start, $period->end)) {
            $this->warning('unlinked_contractor_payments', $count);
        }

        return $this;
    }

    public function countError(string $key, int $count): self
    {
        return $count > 0 ? $this->error($key, $count) : $this;
    }

    /** @return list<array{key: string, severity: string, count?: int, params?: array<string, scalar>}> */
    public function all(): array
    {
        return $this->items;
    }

    private function add(string $key, string $severity, int $count, array $params): self
    {
        $this->items[] = array_filter(
            ['key' => $key, 'severity' => $severity, 'count' => $count ?: null, 'params' => $params ?: null],
            fn ($value) => $value !== null,
        );

        return $this;
    }
}
