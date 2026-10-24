<?php

namespace App\Tax\Calculators;

use App\Models\TaxRule;
use App\Tax\Percent;
use App\Tax\TaxRuleBook;
use Carbon\CarbonInterface;

/**
 * Per-employee payroll taxes. PIT is withheld from gross pay; INPS is a part of the PIT amount that goes to
 * the employee's pension account; social tax is the employer's cost on top of gross pay.
 */
class PayrollCalculator
{
    public function __construct(private readonly TaxRuleBook $rules) {}

    /** @return array{gross: int, pit: int, inps: int, social: int, net: int} */
    public function line(int $grossMinor, CarbonInterface $on, bool $budgetOrganisation): array
    {
        [$pit, $inps, $social] = $this->rules($on, $budgetOrganisation);

        $pitMinor = Percent::of($grossMinor, $pit->value);

        return [
            'gross' => $grossMinor,
            'pit' => $pitMinor,
            'inps' => min($pitMinor, Percent::of($grossMinor, $inps->value)),
            'social' => Percent::of($grossMinor, $social->value),
            'net' => $grossMinor - $pitMinor,
        ];
    }

    /** @return array{0: TaxRule, 1: TaxRule, 2: TaxRule} PIT, INPS and social tax rules in force on the date */
    public function rules(CarbonInterface $on, bool $budgetOrganisation): array
    {
        return [
            $this->rules->rule('pit.standard', $on),
            $this->rules->rule('inps.employee', $on),
            $this->rules->rule($budgetOrganisation ? 'social.budget' : 'social.general', $on),
        ];
    }
}
