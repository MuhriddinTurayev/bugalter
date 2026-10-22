<?php

namespace App\Services;

use App\Models\CompanySetting;

/**
 * The company the current request works for.
 *
 * In "single company dev mode" there is one company_settings row and no tenant.
 * Once multi-tenancy arrives (one database per company) only this class changes:
 * the rest of the application never hardcodes a company id.
 */
class CurrentCompany
{
    private ?CompanySetting $settings = null;

    public function isSingleCompany(): bool
    {
        return (bool) config('app.single_company');
    }

    /** Tenant identifier; always null in single company mode. */
    public function id(): ?string
    {
        return null;
    }

    public function settings(): CompanySetting
    {
        return $this->settings ??= CompanySetting::query()->first()
            ?? new CompanySetting(['name' => config('app.name')]);
    }

    public function name(): string
    {
        return $this->settings()->name;
    }

    public function forget(): void
    {
        $this->settings = null;
    }
}
