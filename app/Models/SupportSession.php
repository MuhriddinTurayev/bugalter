<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable(['staff_id', 'tenant_id', 'reason', 'started_at', 'expires_at', 'ended_at', 'ip'])]
class SupportSession extends Model
{
    use CentralConnection;

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /** @return BelongsTo<PlatformStaff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'staff_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
