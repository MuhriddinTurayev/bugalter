<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * One company, with its own database. The id is the slug, which is also its subdomain.
 *
 * @property string $id
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /** @var array<string, string> */
    protected $casts = [
        'status' => TenantStatus::class,
        'owner_invited_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    /** The id is the company's slug, chosen by a person; it is never generated or auto-incremented. */
    public function getIncrementing()
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }

    /** @return list<string> */
    public static function getCustomColumns(): array
    {
        return [
            'id', 'name', 'stir', 'plan_id', 'status', 'owner_name', 'owner_email', 'owner_phone',
            'owner_invited_at', 'subscription_ends_at', 'last_activity_at', 'created_at', 'updated_at',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function host(): string
    {
        return $this->id.'.'.config('platform.domain');
    }

    public function url(): string
    {
        return config('platform.scheme').'://'.$this->host();
    }

    public function isSubscriptionExpired(): bool
    {
        return $this->subscription_ends_at !== null && $this->subscription_ends_at->isPast();
    }
}
