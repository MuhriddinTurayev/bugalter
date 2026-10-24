<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Every signing (PLAN section 9). Append-only; never holds keys, passwords or document contents. */
#[Fillable(['signing_key_id', 'tax_report_id', 'signer', 'document_hash', 'signature_hash', 'actor_type', 'actor_name', 'approval_ref', 'mandate_id', 'purpose'])]
class SignatureLog extends Model
{
    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The signature log is append-only.'));
        static::deleting(fn () => throw new LogicException('The signature log is append-only.'));
    }

    /** @return BelongsTo<SigningKey, $this> */
    public function key(): BelongsTo
    {
        return $this->belongsTo(SigningKey::class, 'signing_key_id');
    }

    /** @return BelongsTo<TaxReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(TaxReport::class, 'tax_report_id');
    }
}
