<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'currency', 'bank_name', 'account_number', 'opening_balance_minor', 'is_active'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'type' => AccountType::class,
        'opening_balance_minor' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Current balance in minor units; only completed transactions count. */
    public function balanceMinor(): int
    {
        $sums = $this->transactions()
            ->where('status', 'completed')
            ->selectRaw('type, SUM(amount_minor) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return $this->opening_balance_minor
            + (int) ($sums[TransactionType::Income->value] ?? 0)
            - (int) ($sums[TransactionType::Expense->value] ?? 0);
    }
}
