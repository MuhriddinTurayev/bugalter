<?php

namespace App\Models;

use App\Enums\PlatformRole;
use Database\Factories\PlatformStaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** A member of the platform owner's staff. Lives in the central database, never in a company's. */
#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'invitation_token_hash'])]
class PlatformStaff extends Authenticatable
{
    /** @use HasFactory<PlatformStaffFactory> */
    use CentralConnection, HasFactory;

    protected $table = 'platform_staff';

    /** @var array<string, string> */
    protected $casts = [
        'password' => 'hashed',
        'role' => PlatformRole::class,
        'is_active' => 'boolean',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
        'invitation_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === PlatformRole::SuperAdmin;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/u', trim($this->name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
