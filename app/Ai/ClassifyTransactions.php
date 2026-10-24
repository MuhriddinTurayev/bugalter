<?php

namespace App\Ai;

use App\Models\Category;
use App\Models\Transaction;

/**
 * Proposals for unclassified transactions: the company history first (deterministic), then the AI for what
 * is left, when a provider is configured. Used by the autopilot and by the "classify now" button.
 */
class ClassifyTransactions
{
    public function __construct(
        private readonly TransactionClassifier $classifier,
        private readonly ProposalService $proposals,
        private readonly AiSettings $ai,
        private readonly AiAccountant $accountant,
    ) {}

    /** @return int number of proposals made; AI errors are thrown as AiException after the rule pass */
    public function __invoke(bool $withAi = true, int $limit = 200): int
    {
        $count = 0;

        foreach (Transaction::query()->whereNull('category_id')->latest('occurred_at')->limit($limit)->get() as $transaction) {
            $suggestion = $this->classifier->suggest($transaction);
            $category = $suggestion ? Category::query()->find($suggestion['category_id']) : null;

            if ($category !== null && $category->type === $transaction->type) {
                $this->proposals->proposeCategory($transaction, $category, $suggestion['confidence'], $suggestion['reason'], 'rules');
                $count++;
            }
        }

        if ($withAi && $this->ai->isConfigured() && Transaction::query()->whereNull('category_id')->whereDoesntHave('proposals')->exists()) {
            $count += $this->accountant->classifyPending(30);
        }

        return $count;
    }
}
