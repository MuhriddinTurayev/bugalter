<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id', 'position', 'name', 'unit', 'quantity', 'unit_price_minor', 'vat_rate_bp',
    'subtotal_minor', 'vat_minor', 'total_minor',
])]
class DocumentLine extends Model
{
    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price_minor' => 'integer',
        'vat_rate_bp' => 'integer',
        'subtotal_minor' => 'integer',
        'vat_minor' => 'integer',
        'total_minor' => 'integer',
    ];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
