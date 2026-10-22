<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A company's own audit log (lives in the company's database). Append-only. */
#[Fillable(['actor_type', 'actor_name', 'action', 'reason', 'details'])]
class AuditLog extends Model
{
    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit log is append-only.'));
        static::deleting(fn () => throw new LogicException('The audit log is append-only.'));
    }
}
