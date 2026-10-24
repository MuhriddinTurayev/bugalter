<?php

namespace App\Support;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Closure;

/** Runs a callback inside the context of every active company (or the single dev company). */
final class ForEachCompany
{
    /**
     * @param  Closure(string): mixed  $callback  receives the company id ("default" in single company mode)
     * @return array<string, mixed> results by company id
     */
    public static function run(Closure $callback, ?string $only = null): array
    {
        if (config('app.single_company')) {
            return ['default' => $callback('default')];
        }

        $results = [];

        Tenant::query()
            ->where('status', TenantStatus::Active)
            ->when($only, fn ($q) => $q->whereKey($only))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($callback, &$results) {
                $results[$tenant->id] = $tenant->run(fn () => $callback($tenant->id));
            });

        return $results;
    }
}
