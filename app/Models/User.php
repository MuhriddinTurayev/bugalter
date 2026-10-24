<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password', 'role', 'locale', 'is_active'])]
#[Hidden(['password', 'remember_token', 'invitation_token_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, string> */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'invitation_expires_at' => 'datetime',
        'password' => 'hashed',
        'role' => Role::class,
        'is_active' => 'boolean',
    ];

    /** @return HasMany<UserPermission, $this> */
    public function grantedPermissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /** A role's default permissions plus the ones the owner granted to this person. */
    public function hasPermission(Permission $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (in_array($permission, Permission::defaultsFor($this->role), true)) {
            return true;
        }

        return $this->grantedPermissions->contains('permission', $permission->value);
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return array_values(array_filter(Permission::cases(), fn (Permission $p) => $this->hasPermission($p)));
    }

    public function isInvitationPending(): bool
    {
        return $this->password === null && $this->invitation_token_hash !== null;
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/u', trim($this->name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    public function firstName(): string
    {
        return (string) strtok($this->name, ' ');
    }
}
