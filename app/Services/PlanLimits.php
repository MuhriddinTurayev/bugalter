<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Limits of the company's plan (entered in the platform panel; a missing limit means unlimited) and the
 * subscription end date. The single company dev mode has no plan and no limits.
 */
class PlanLimits
{
    public const USERS = 'max_users';

    public const DOCUMENTS = 'max_documents_per_month';

    public const TRANSACTIONS = 'max_transactions_per_month';

    public function tenant(): ?Tenant
    {
        $tenant = tenancy()->initialized ? tenant() : null;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public function plan(): ?Plan
    {
        return $this->tenant()?->plan;
    }

    public function limit(string $key): ?int
    {
        return $this->plan()?->limit($key);
    }

    public function used(string $key): int
    {
        return match ($key) {
            self::USERS => User::query()->where('is_active', true)->count(),
            self::DOCUMENTS => Document::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            self::TRANSACTIONS => Transaction::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            default => 0,
        };
    }

    /** Throws a validation error when adding $adding more would exceed the plan. */
    public function ensure(string $key, int $adding = 1): void
    {
        $limit = $this->limit($key);

        if ($limit !== null && $this->used($key) + $adding > $limit) {
            throw ValidationException::withMessages([
                'limit' => __('ui.limits.reached', ['limit' => $limit, 'what' => __('ui.limits.'.$key)]),
            ]);
        }
    }

    public function isSubscriptionExpired(): bool
    {
        return (bool) $this->tenant()?->isSubscriptionExpired();
    }

    /** @return list<array{key: string, label: string, used: int, limit: ?int}> */
    public function usage(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => __('ui.limits.'.$key),
            'used' => $this->used($key),
            'limit' => $this->limit($key),
        ], [self::USERS, self::DOCUMENTS, self::TRANSACTIONS]);
    }
}
