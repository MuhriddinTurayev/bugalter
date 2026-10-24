<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A paired Windows computer running the desktop agent. Only the SHA-256 of its token is stored; the token is
 * shown once when the owner pairs the computer. It never receives the ERI key or its password.
 */
#[Fillable(['name', 'token_hash', 'token_prefix', 'created_by', 'created_by_name', 'last_seen_at', 'last_ip', 'agent_version', 'environment', 'revoked_at', 'revoked_by_name'])]
#[Hidden(['token_hash'])]
class DesktopAgent extends Model
{
    /** An agent seen within this many minutes counts as online. */
    public const ONLINE_MINUTES = 3;

    /** @var array<string, string> */
    protected $casts = [
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'environment' => 'array',
    ];

    /** Creates an agent and returns it with its plain token, which is never stored or shown again. */
    public static function pair(string $name, User $owner): array
    {
        $token = 'bga_'.Str::random(48);

        $agent = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 12),
            'created_by' => $owner->id,
            'created_by_name' => $owner->name,
        ]);

        return [$agent, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }

    /** An agent that can take work now. */
    public static function online(): ?self
    {
        return static::query()->whereNull('revoked_at')->where('last_seen_at', '>', now()->subMinutes(self::ONLINE_MINUTES))->latest('last_seen_at')->first();
    }

    public function isOnline(): bool
    {
        return $this->revoked_at === null && $this->last_seen_at !== null && $this->last_seen_at->isAfter(now()->subMinutes(self::ONLINE_MINUTES));
    }
}
