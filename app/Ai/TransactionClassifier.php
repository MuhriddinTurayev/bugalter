<?php

namespace App\Ai;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Deterministic category suggestions from the company's own history: the category a contractor is usually
 * booked under, transactions with the same description, or a category name in the description. Works with
 * no AI provider at all; the AI can use it as a hint.
 */
class TransactionClassifier
{
    /** @var Collection<int, Transaction>|null */
    private ?Collection $history = null;

    /** @return array{category_id: int, confidence: int, reason: string}|null */
    public function suggest(Transaction $transaction): ?array
    {
        $history = $this->history()->where('type', $transaction->type);

        if ($transaction->contractor_id !== null) {
            $same = $history->where('contractor_id', $transaction->contractor_id);

            if ($same->count() >= 2) {
                $top = $same->countBy('category_id')->sortDesc();
                $share = intdiv($top->first() * 100, $same->count());

                return [
                    'category_id' => (int) $top->keys()->first(),
                    'confidence' => $same->count() >= 3 && $share >= 80 ? 95 : 75,
                    'reason' => __('ai.reasons.contractor', ['count' => $same->count(), 'share' => $share]),
                ];
            }
        }

        $words = $this->words($transaction->description);

        if ($words !== []) {
            $exact = $history->filter(fn (Transaction $t) => $this->words($t->description) === $words);

            if ($exact->count() >= 2) {
                return [
                    'category_id' => (int) $exact->countBy('category_id')->sortDesc()->keys()->first(),
                    'confidence' => 90,
                    'reason' => __('ai.reasons.same_description', ['count' => $exact->count()]),
                ];
            }

            $category = Category::query()->where('is_active', true)->where('type', $transaction->type)->get()
                ->first(fn (Category $c) => array_intersect($this->words($c->label()), $words) !== []);

            if ($category !== null) {
                return [
                    'category_id' => $category->id,
                    'confidence' => 60,
                    'reason' => __('ai.reasons.category_name', ['category' => $category->label()]),
                ];
            }
        }

        return null;
    }

    /** @return Collection<int, Transaction> classified transactions, most recent first */
    private function history(): Collection
    {
        return $this->history ??= Transaction::query()
            ->whereNotNull('category_id')
            ->latest('occurred_at')
            ->limit(2000)
            ->get(['id', 'type', 'category_id', 'contractor_id', 'description']);
    }

    /** @return list<string> */
    private function words(?string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::lower((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) >= 4 && ! ctype_digit($w))));
        sort($words);

        return $words;
    }
}
