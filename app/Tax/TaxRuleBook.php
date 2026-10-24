<?php

namespace App\Tax;

use App\Enums\TaxReportType;
use App\Models\TaxDeadline;
use App\Models\TaxRule;
use Carbon\CarbonInterface;

/** Reads the versioned rules tables. The only way calculators get a rate, an amount or a deadline. */
class TaxRuleBook
{
    /** @var array<string, TaxRule|null> */
    private array $rules = [];

    /** @var array<string, TaxDeadline|null> */
    private array $deadlines = [];

    /** The version of a rule in force on a date. */
    public function rule(string $code, CarbonInterface $on): TaxRule
    {
        $key = $code.'@'.$on->toDateString();

        $rule = $this->rules[$key] ??= TaxRule::query()
            ->where('code', $code)
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->first();

        return $rule ?? throw new MissingTaxRule($code, $on->toDateString());
    }

    public function has(string $code, CarbonInterface $on): bool
    {
        try {
            $this->rule($code, $on);

            return true;
        } catch (MissingTaxRule) {
            return false;
        }
    }

    public function deadline(TaxReportType $type, CarbonInterface $on): ?TaxDeadline
    {
        $key = $type->value.'@'.$on->toDateString();

        if (! array_key_exists($key, $this->deadlines)) {
            $this->deadlines[$key] = TaxDeadline::query()
                ->where('report_type', $type->value)
                ->whereDate('effective_from', '<=', $on)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
                ->orderByDesc('effective_from')
                ->first();
        }

        return $this->deadlines[$key];
    }

    public function allVerified(): bool
    {
        return ! TaxRule::query()->where('status', '!=', TaxRule::VERIFIED)->exists()
            && ! TaxDeadline::query()->where('status', '!=', TaxRule::VERIFIED)->exists();
    }

    public function isLoaded(): bool
    {
        return TaxRule::query()->exists();
    }

    public function forget(): void
    {
        $this->rules = [];
        $this->deadlines = [];
    }
}
