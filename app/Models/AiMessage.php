<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ai_conversation_id', 'role', 'content', 'tool_calls', 'provider', 'model', 'input_tokens', 'output_tokens', 'is_error'])]
class AiMessage extends Model
{
    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'tool_calls' => 'array',
        'is_error' => 'boolean',
        'created_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_error' => false,
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $message) => $message->created_at ??= now());
    }

    /** @return BelongsTo<AiConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
