<?php

namespace App\Ai\Drivers;

use App\Ai\AiConfig;
use App\Ai\AiException;
use App\Ai\AiTurn;
use App\Enums\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ChatGPT (OpenAI), Gemini (its OpenAI-compatible endpoint) and DeepSeek: the Chat Completions API with
 * function calling. Each provider has its own base URL, key and model; the request shape is shared.
 */
class OpenAiCompatibleDriver implements AiDriver
{
    public function chat(AiConfig $config, string $system, array $messages, array $tools): AiTurn
    {
        $body = [
            'model' => $config->model,
            'messages' => [['role' => 'system', 'content' => $system], ...$this->messages($messages)],
        ];

        if ($tools !== []) {
            $body['tools'] = array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'] + ['properties' => new \stdClass],
                ],
            ], $tools);
        }

        // OpenAI reasoning models take max_completion_tokens; the compatible APIs take max_tokens.
        $body[$config->provider === AiProvider::OpenAi ? 'max_completion_tokens' : 'max_tokens'] = 8000;

        try {
            $response = Http::withToken($config->apiKey)
                ->acceptJson()
                ->timeout(150)
                ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post(rtrim($config->baseUrl, '/').'/chat/completions', $body);
        } catch (ConnectionException) {
            throw new AiException(__('ai.errors.connection'));
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new AiException(__('ai.errors.auth'));
        }

        if ($response->status() === 429) {
            throw new AiException(__('ai.errors.rate_limit'));
        }

        if (! $response->successful()) {
            throw new AiException(__('ai.errors.provider', ['status' => (string) $response->status()]));
        }

        $message = $response->json('choices.0.message') ?? [];
        $calls = [];

        foreach ($message['tool_calls'] ?? [] as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $calls[] = [
                'id' => (string) $call['id'],
                'name' => (string) ($call['function']['name'] ?? ''),
                'input' => is_array($arguments) ? $arguments : [],
            ];
        }

        // Replayed unchanged within the turn; keeps the provider's own tool_calls ids.
        $raw = array_filter([
            'role' => 'assistant',
            'content' => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? null,
        ], fn ($value) => $value !== null);

        return new AiTurn(
            text: trim((string) ($message['content'] ?? '')),
            toolCalls: $calls,
            raw: $raw,
            stopReason: (string) $response->json('choices.0.finish_reason', 'stop'),
            inputTokens: (int) $response->json('usage.prompt_tokens', 0),
            outputTokens: (int) $response->json('usage.completion_tokens', 0),
        );
    }

    private function messages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'tool_results') {
                foreach ($message['results'] as $result) {
                    $out[] = ['role' => 'tool', 'tool_call_id' => $result['id'], 'content' => $result['content']];
                }

                continue;
            }

            $out[] = $message['role'] === 'assistant'
                ? ($message['raw'] ?? ['role' => 'assistant', 'content' => $message['content']])
                : ['role' => 'user', 'content' => $message['content']];
        }

        return $out;
    }
}
