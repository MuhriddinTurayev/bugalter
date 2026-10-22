<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Append-only audit trail of everything done in the platform panel. */
#[Fillable(['staff_id', 'staff_name', 'action', 'tenant_id', 'reason', 'details', 'ip'])]
class PlatformAuditLog extends Model
{
    use CentralConnection;

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The platform audit log is append-only.'));
        static::deleting(fn () => throw new LogicException('The platform audit log is append-only.'));
    }
}
