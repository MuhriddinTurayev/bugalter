<?php

namespace App\Ai;

/** One model response, in a provider-neutral shape. */
final class AiTurn
{
    /**
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls
     * @param  mixed  $raw  the provider's own assistant message, replayed unchanged inside the same turn
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly mixed $raw,
        public readonly string $stopReason,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
