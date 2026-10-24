<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id', 'category_id', 'contractor_id', 'document_id', 'type', 'amount_minor', 'occurred_at',
    'description', 'reference', 'status', 'created_by',
])]
class Transaction extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'type' => TransactionType::class,
        'amount_minor' => 'integer',
        'occurred_at' => 'datetime',
    ];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Contractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class)->withTrashed();
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<AiProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(AiProposal::class, 'subject_id')->where('subject_type', 'transaction');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    /** Signed amount: income positive, expense negative. */
    public function signedAmountMinor(): int
    {
        return $this->type === TransactionType::Income ? $this->amount_minor : -$this->amount_minor;
    }

    public function title(): string
    {
        // Both relations are optional (nullable foreign keys).
        $contractor = $this->getRelationValue('contractor');
        $category = $this->getRelationValue('category');

        return $contractor->name ?? $this->description ?? ($category ? $category->label() : '—');
    }
}
