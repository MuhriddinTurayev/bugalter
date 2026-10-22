<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable(['key', 'name', 'description', 'price_minor', 'currency', 'billing_period', 'limits', 'is_active'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use CentralConnection, HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'price_minor' => 'integer',
        'limits' => 'array',
        'is_active' => 'boolean',
    ];

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function priceLabel(): string
    {
        return $this->price_minor === null
            ? __('platform.plans.price_not_set')
            : Money::format($this->price_minor).' / '.__('platform.plans.periods.'.$this->billing_period);
    }

    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
