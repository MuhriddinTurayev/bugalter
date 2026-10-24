<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A data change suggested by the AI (or the rule-based classifier); applied only after approval or auto-apply. */
#[Fillable([
    'type', 'subject_type', 'subject_id', 'payload', 'confidence', 'reason', 'source', 'status',
    'ai_conversation_id', 'decided_by_name', 'decided_at',
])]
class AiProposal extends Model
{
    public const CLASSIFY_TRANSACTION = 'classify_transaction';

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'confidence' => 'integer',
        'status' => ProposalStatus::class,
        'decided_at' => 'datetime',
    ];

    public function transaction(): ?Transaction
    {
        return $this->subject_type === 'transaction' ? Transaction::query()->find($this->subject_id) : null;
    }
}
