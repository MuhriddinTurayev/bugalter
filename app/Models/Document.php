<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type', 'direction', 'number', 'contractor_id', 'issue_date', 'due_date', 'status', 'currency',
    'subtotal_minor', 'vat_minor', 'total_minor', 'notes', 'created_by', 'paid_at',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'type' => DocumentType::class,
        'status' => DocumentStatus::class,
        'issue_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'subtotal_minor' => 'integer',
        'vat_minor' => 'integer',
        'total_minor' => 'integer',
    ];

    /** @return BelongsTo<Contractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class)->withTrashed();
    }

    /** @return HasMany<DocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isIncoming(): bool
    {
        return $this->direction === 'in';
    }

    public function isEditable(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isOverdue(): bool
    {
        return $this->status === DocumentStatus::Sent
            && $this->due_date !== null
            && $this->due_date->isBefore(today());
    }
}
