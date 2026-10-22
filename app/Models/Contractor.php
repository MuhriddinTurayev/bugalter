<?php

namespace App\Models;

use App\Enums\ContractorType;
use Database\Factories\ContractorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'stir', 'type', 'phone', 'email', 'address', 'bank_name', 'bank_account', 'mfo', 'notes', 'is_active',
])]
class Contractor extends Model
{
    /** @use HasFactory<ContractorFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, string> */
    protected $casts = [
        'type' => ContractorType::class,
        'is_active' => 'boolean',
    ];

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('stir', 'like', $like)
            ->orWhere('phone', 'like', $like)
            ->orWhere('email', 'like', $like));
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr(trim($this->name), 0, 1));
    }
}
