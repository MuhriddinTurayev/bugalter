<?php

namespace App\Tax;

use RuntimeException;

/** A calculation needs a rule that is not in the rules tables. Never guessed: the calculation stops. */
class MissingTaxRule extends RuntimeException
{
    public function __construct(public readonly string $ruleCode, public readonly string $date)
    {
        parent::__construct("Tax rule [$ruleCode] is not defined for $date. Add it to docs/tax/rules.json and run tax:rules-sync.");
    }
}
