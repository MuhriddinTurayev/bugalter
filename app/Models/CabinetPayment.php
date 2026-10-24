<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A payment order as the tax cabinet lists it. Read from the cabinet by the desktop agent; never typed in by hand. */
#[Fillable([
    'order_number', 'order_date', 'tax_code', 'tax', 'amount_minor', 'status_text', 'is_paid', 'payer_bank_code',
    'payer_account', 'receiver_account', 'year', 'first_seen_at', 'last_seen_at',
])]
class CabinetPayment extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'order_date' => 'date',
        'amount_minor' => 'integer',
        'is_paid' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** "•••• 8001": an account is never shown in full. */
    public static function mask(?string $account): string
    {
        return $account === null || strlen($account) < 4 ? '—' : '•••• '.substr($account, -4);
    }
}
