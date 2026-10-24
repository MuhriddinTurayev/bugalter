<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One version of a tax rate or amount, shared by every company. Seeded from docs/tax/rules.json.
 *
 * @property string $code
 * @property string $kind rate (basis points) or amount (tiyin)
 * @property int $value
 */
#[Fillable(['code', 'kind', 'value', 'effective_from', 'effective_to', 'name', 'source', 'status', 'verified_by', 'verified_at', 'version'])]
class TaxRule extends Model
{
    use CentralConnection;

    public const VERIFIED = 'verified';

    public const NEEDS_VERIFICATION = 'needs_verification';

    /** @var array<string, string> */
    protected $casts = [
        'value' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'name' => 'array',
        'verified_at' => 'datetime',
        'version' => 'integer',
    ];

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    public function label(): string
    {
        return $this->name[app()->getLocale()] ?? $this->name['uz'] ?? $this->code;
    }

    /** "12%" for a rate, a money amount for an amount. */
    public function valueLabel(): string
    {
        if ($this->kind === 'rate') {
            return rtrim(rtrim(number_format($this->value / 100, 2, ',', ''), '0'), ',').'%';
        }

        return Money::format($this->value);
    }

    /** A compact reference stored with every calculation that used this rule. */
    public function reference(): array
    {
        return [
            'code' => $this->code,
            'value' => $this->value,
            'kind' => $this->kind,
            'version' => $this->version,
            'effective_from' => $this->effective_from->toDateString(),
            'verified' => $this->isVerified(),
        ];
    }
}
