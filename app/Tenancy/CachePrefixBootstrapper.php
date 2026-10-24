<?php

namespace App\Tenancy;

use Illuminate\Cache\CacheManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Gives every company its own cache key prefix, so no cached value can cross from one company to another.
 * Unlike stancl's CacheTenancyBootstrapper it needs no cache tags, so it works with the database, file and
 * Redis stores alike.
 */
class CachePrefixBootstrapper implements TenancyBootstrapper
{
    private ?string $original = null;

    public function bootstrap(Tenant $tenant)
    {
        $this->original ??= (string) config('cache.prefix');

        config(['cache.prefix' => $this->original.'tenant_'.$tenant->getTenantKey().'_']);
        $this->refresh();
    }

    public function revert()
    {
        if ($this->original !== null) {
            config(['cache.prefix' => $this->original]);
            $this->refresh();
        }
    }

    /** Stores are built lazily with the prefix of their time: drop them so the next use picks up the new one. */
    private function refresh(): void
    {
        /** @var CacheManager $cache */
        $cache = app('cache');

        foreach (array_keys((array) config('cache.stores')) as $store) {
            $cache->forgetDriver($store);
        }

        app()->forgetInstance('cache.store');
    }
}
