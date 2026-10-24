<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * An ERI/E-imzo key in the vault. The encrypted columns are never serialised, logged or shown; only the
 * Signer service decrypts them, in memory, for one signing.
 */
#[Fillable([
    'label', 'owner_name', 'owner_tin', 'serial_number', 'valid_from', 'valid_to', 'status', 'encrypted_key',
    'encrypted_password', 'encrypted_data_key', 'fingerprint', 'expiry_warnings_sent', 'uploaded_by', 'revoked_at', 'revoked_by_name',
])]
#[Hidden(['encrypted_key', 'encrypted_password', 'encrypted_data_key'])]
class SigningKey extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'expiry_warnings_sent' => 'array',
        'revoked_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->status === 'active' && ! $this->valid_to->isBefore(today());
    }

    public function daysLeft(): int
    {
        return (int) today()->diffInDays($this->valid_to, false);
    }

    public static function usable(): ?self
    {
        return static::query()->where('status', 'active')->whereDate('valid_to', '>=', today())->latest('id')->first();
    }

    public function statusLabel(): string
    {
        if ($this->status === 'active' && $this->valid_to->isBefore(today())) {
            return __('autopilot.key_statuses.expired');
        }

        return __('autopilot.key_statuses.'.$this->status);
    }
}
