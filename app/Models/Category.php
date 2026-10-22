<?php

namespace App\Models;

use App\Enums\TransactionType;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;

#[Fillable(['key', 'name', 'type', 'is_active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'type' => TransactionType::class,
        'is_active' => 'boolean',
    ];

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** System categories are translated by key; custom ones show the name the user typed. */
    public function label(): string
    {
        if ($this->key !== null && Lang::has('ui.categories.'.$this->key)) {
            return __('ui.categories.'.$this->key);
        }

        return $this->name;
    }
}
