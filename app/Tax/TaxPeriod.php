<?php

namespace App\Tax;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/** A tax period: a calendar month or quarter. */
final class TaxPeriod
{
    private function __construct(
        public readonly string $kind,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public static function month(int $year, int $month): self
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return new self('month', $start, $start->endOfMonth()->startOfDay());
    }

    public static function quarter(int $year, int $quarter): self
    {
        $start = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();

        return new self('quarter', $start, $start->addMonths(2)->endOfMonth()->startOfDay());
    }

    /** The period of a kind that contains a date. */
    public static function containing(string $kind, CarbonInterface $date): self
    {
        return match ($kind) {
            'month' => self::month($date->year, $date->month),
            'quarter' => self::quarter($date->year, $date->quarter),
            default => throw new InvalidArgumentException("Unknown period kind [$kind]."),
        };
    }

    /** Rebuilds a period from a start date (as stored on a report). */
    public static function fromStart(string $kind, CarbonInterface $start): self
    {
        return self::containing($kind, $start);
    }

    public function previous(): self
    {
        return self::containing($this->kind, $this->start->subDay());
    }

    public function next(): self
    {
        return self::containing($this->kind, $this->end->addDay());
    }

    public function isClosed(?CarbonInterface $today = null): bool
    {
        return $this->end->isBefore(($today ?? CarbonImmutable::today())->startOfDay());
    }

    public function yearStart(): CarbonImmutable
    {
        return $this->start->startOfYear();
    }

    public function label(): string
    {
        return $this->kind === 'month'
            ? __('ui.months_full.'.$this->start->month).' '.$this->start->year
            : __('tax.quarter', ['quarter' => $this->start->quarter, 'year' => $this->start->year]);
    }

    /** @return list<self> months inside this period */
    public function months(): array
    {
        $months = [];
        for ($cursor = $this->start; $cursor <= $this->end; $cursor = $cursor->addMonth()) {
            $months[] = self::month($cursor->year, $cursor->month);
        }

        return $months;
    }
}
