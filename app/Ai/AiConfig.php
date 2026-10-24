<?php

namespace App\Ai;

use App\Enums\AiProvider;

/** The provider, model and key one request uses. The key never leaves the server and is never logged. */
final class AiConfig
{
    public function __construct(
        public readonly AiProvider $provider,
        public readonly string $model,
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $baseUrl,
        public readonly string $source, // company | platform
    ) {}

    public function __debugInfo(): array
    {
        return ['provider' => $this->provider->value, 'model' => $this->model, 'baseUrl' => $this->baseUrl, 'source' => $this->source, 'apiKey' => '***'];
    }
}
