<?php

namespace App\Tax;

use App\Enums\DocumentStatus;
use App\Enums\PayrollStatus;
use App\Enums\TransactionType;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\PayrollRun;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The bookkeeping figures the tax calculators read. Revenue and costs are recognised from documents
 * (invoices and acts that were sent or paid). Money movements without a document count only when they
 * have no contractor (retail sales, rent, utilities): a payment from or to a contractor without a document
 * settles an invoice, and counting it again would double the figure, so it is reported as an issue instead.
 */
class Ledger
{
    /** Categories whose movements are not revenue or deductible costs on their own. */
    public const TAX_PAYMENT_CATEGORY = 'taxes_fees';

    public const SALARY_CATEGORY = 'salaries';

    /** @return array{count: int, subtotal: int, vat: int, total: int} */
    public function documents(string $direction, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->issuedDocuments($direction, $from, $to)
            ->toBase()
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(subtotal_minor), 0) as s, COALESCE(SUM(vat_minor), 0) as v, COALESCE(SUM(total_minor), 0) as t')
            ->first();

        return [
            'count' => (int) $row->c,
            'subtotal' => (int) $row->s,
            'vat' => (int) $row->v,
            'total' => (int) $row->t,
        ];
    }

    /** Distinct VAT rates (basis points) used on document lines in the period. @return list<int> */
    public function vatRatesUsed(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DocumentLine::query()
            ->whereIn('document_id', Document::query()
                ->whereIn('status', [DocumentStatus::Sent, DocumentStatus::Paid])
                ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
                ->select('id'))
            ->distinct()
            ->pluck('vat_rate_bp')
            ->map(fn ($rate) => (int) $rate)
            ->sort()
            ->values()
            ->all();
    }

    /** Income without a document and without a contractor (retail sales and other income), gross. */
    public function retailIncome(CarbonImmutable $from, CarbonImmutable $to, array $excludeCategories = []): int
    {
        return (int) $this->undocumented(TransactionType::Income, $from, $to)
            ->whereNull('contractor_id')
            ->when($excludeCategories !== [], fn (Builder $query) => $query->whereHas(
                'category',
                fn (Builder $q) => $q->where(fn (Builder $c) => $c->whereNull('key')->orWhereNotIn('key', $excludeCategories)),
            ))
            ->sum('amount_minor');
    }

    /** Costs without a document and without a contractor, except tax payments and (optionally) salaries. */
    public function undocumentedExpenses(CarbonImmutable $from, CarbonImmutable $to, bool $withSalaries): int
    {
        $exclude = $withSalaries ? [self::TAX_PAYMENT_CATEGORY] : [self::TAX_PAYMENT_CATEGORY, self::SALARY_CATEGORY];

        return (int) $this->undocumented(TransactionType::Expense, $from, $to)
            ->whereNull('contractor_id')
            ->whereHas('category', fn (Builder $q) => $q->where(fn (Builder $c) => $c->whereNull('key')->orWhereNotIn('key', $exclude)))
            ->sum('amount_minor');
    }

    public function salaryPayments(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $this->undocumented(TransactionType::Expense, $from, $to)
            ->whereHas('category', fn (Builder $q) => $q->where('key', self::SALARY_CATEGORY))
            ->sum('amount_minor');
    }

    /** The approved (or paid) payroll of a month, if there is one. */
    public function payrollFor(CarbonImmutable $monthStart): ?PayrollRun
    {
        return PayrollRun::query()
            ->whereDate('period', $monthStart->toDateString())
            ->whereIn('status', [PayrollStatus::Approved, PayrollStatus::Paid])
            ->first();
    }

    /** Any bookkeeping at all in the period: a sent document, a money movement or a payroll run. */
    public function hasRecords(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return Document::query()->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->where('status', '!=', DocumentStatus::Draft)->exists()
            || Transaction::query()->whereBetween('occurred_at', [$from->startOfDay(), $to->endOfDay()])->exists()
            || PayrollRun::query()->whereBetween('period', [$from->toDateString(), $to->toDateString()])->exists();
    }

    public function unclassifiedCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->completedBetween($from, $to)->whereNull('category_id')->count();
    }

    public function pendingCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Transaction::query()
            ->where('status', 'pending')
            ->whereBetween('occurred_at', [$from->startOfDay(), $to->endOfDay()])
            ->count();
    }

    /** Payments from or to a contractor that are not linked to a document. */
    public function unlinkedContractorPayments(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->completedBetween($from, $to)
            ->whereNull('document_id')
            ->whereNotNull('contractor_id')
            ->whereNotNull('category_id')
            ->count();
    }

    public function draftDocumentsCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Document::query()
            ->where('status', DocumentStatus::Draft)
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->count();
    }

    public function documentsWithoutContractorTin(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Document::query()
            ->whereIn('status', [DocumentStatus::Sent, DocumentStatus::Paid])
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('contractor', fn (Builder $q) => $q->whereNull('stir')) // the relation already includes trashed
            ->count();
    }

    private function issuedDocuments(string $direction, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Document::query()
            ->where('direction', $direction)
            ->whereIn('status', [DocumentStatus::Sent, DocumentStatus::Paid])
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()]);
    }

    private function completedBetween(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Transaction::query()
            ->completed()
            ->whereBetween('occurred_at', [$from->startOfDay(), $to->endOfDay()]);
    }

    private function undocumented(TransactionType $type, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->completedBetween($from, $to)
            ->where('type', $type)
            ->whereNull('document_id')
            ->whereNotNull('category_id');
    }
}
