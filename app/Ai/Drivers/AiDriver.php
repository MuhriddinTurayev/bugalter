<?php

namespace App\Ai\Drivers;

use App\Ai\AiConfig;
use App\Ai\AiTurn;

/**
 * Messages are provider-neutral:
 *   ['role' => 'user', 'content' => '...']
 *   ['role' => 'assistant', 'content' => '...', 'raw' => <provider message from this turn>]
 *   ['role' => 'tool_results', 'results' => [['id' => ..., 'name' => ..., 'content' => '<json>', 'is_error' => bool], ...]]
 * Tools: [['name' => ..., 'description' => ..., 'parameters' => <JSON schema>], ...]
 */
interface AiDriver
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     */
    public function chat(AiConfig $config, string $system, array $messages, array $tools): AiTurn;
}
