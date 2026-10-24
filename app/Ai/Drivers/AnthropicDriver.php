<?php

namespace App\Ai\Drivers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Ai\AiConfig;
use App\Ai\AiException;
use App\Ai\AiTurn;
use Psr\Http\Client\ClientInterface;

/**
 * Claude through the official Anthropic PHP SDK. Server-side refusal fallback is on ("default"), and the
 * assistant content of a tool round is passed back unchanged, thinking blocks included.
 */
class AnthropicDriver implements AiDriver
{
    /** @param  ClientInterface|null  $transporter  a PSR-18 client; tests pass a mock, production uses the discovered one */
    public function __construct(private readonly ?ClientInterface $transporter = null) {}

    public function chat(AiConfig $config, string $system, array $messages, array $tools): AiTurn
    {
        $options = ['maxRetries' => 2];

        if ($this->transporter !== null) {
            $options['transporter'] = $this->transporter;
        }

        $client = new Client(apiKey: $config->apiKey, baseUrl: $config->baseUrl, requestOptions: $options);

        $params = [
            'maxTokens' => 16000,
            'messages' => $this->messages($messages),
            'model' => $config->model,
            'system' => [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            'tools' => array_map(fn (array $tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['parameters'],
            ], $tools),
            'fallbacks' => 'default',
            'betas' => ['server-side-fallback-2026-07-01'],
        ];

        if ($this->supportsEffort($config->model)) {
            $params['outputConfig'] = ['effort' => 'medium'];
        }

        try {
            $response = $client->beta->messages->create(...$params);
        } catch (AuthenticationException) {
            throw new AiException(__('ai.errors.auth'));
        } catch (RateLimitException) {
            throw new AiException(__('ai.errors.rate_limit'));
        } catch (APIStatusException $e) {
            throw new AiException(__('ai.errors.provider', ['status' => (string) $e->status]));
        } catch (APIConnectionException) {
            throw new AiException(__('ai.errors.connection'));
        }

        if ($response->stopReason === 'refusal') {
            throw new AiException(__('ai.errors.refusal'));
        }

        $text = '';
        $calls = [];

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $calls[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            }
        }

        return new AiTurn(
            text: trim($text),
            toolCalls: $calls,
            raw: $response->content,
            stopReason: (string) $response->stopReason,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
        );
    }

    /** Neutral messages to the Messages API shape. */
    private function messages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            $out[] = match ($message['role']) {
                'user' => ['role' => 'user', 'content' => $message['content']],
                'assistant' => ['role' => 'assistant', 'content' => $message['raw'] ?? $message['content']],
                'tool_results' => ['role' => 'user', 'content' => array_map(fn (array $result) => [
                    'type' => 'tool_result',
                    'toolUseID' => $result['id'],
                    'content' => $result['content'],
                    'isError' => (bool) ($result['is_error'] ?? false),
                ], $message['results'])],
                default => throw new \InvalidArgumentException('Unknown message role ['.$message['role'].'].'),
            };
        }

        return $out;
    }

    /** output_config.effort exists on Opus 4.5+ and the Claude 5 family; older models reject it. */
    private function supportsEffort(string $model): bool
    {
        return (bool) preg_match('/^claude-(opus-4-[5-9]|(opus|sonnet|haiku|fable|mythos)-5)/', $model);
    }
}
