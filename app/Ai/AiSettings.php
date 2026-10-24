<?php

namespace App\Ai;

use App\Ai\Drivers\AiDriver;
use App\Ai\Drivers\AnthropicDriver;
use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Enums\AiProvider;
use App\Services\CurrentCompany;
use App\Services\Platform\IntegrationSettings;

/**
 * Which provider, model and key the AI accountant uses for the current company: the company's own key when
 * the owner entered one, otherwise the platform's default provider.
 */
class AiSettings
{
    public function __construct(private readonly CurrentCompany $company, private readonly IntegrationSettings $platform) {}

    public function resolve(): ?AiConfig
    {
        $settings = $this->company->settings();
        $platform = $this->platform->ai();

        $companyProvider = AiProvider::tryFrom((string) $settings->ai_provider);

        if ($companyProvider !== null && filled($settings->ai_api_key)) {
            return new AiConfig(
                $companyProvider,
                $settings->ai_model ?: $platform['providers'][$companyProvider->value]['model'],
                $settings->ai_api_key,
                $platform['providers'][$companyProvider->value]['base_url'],
                'company',
            );
        }

        // A company that chose a provider without its own key uses the platform key of that provider.
        $provider = $companyProvider ?? AiProvider::tryFrom((string) $platform['default']);

        if ($provider === null || ! $platform['providers'][$provider->value]['enabled']) {
            return null;
        }

        $key = $this->platform->aiKey($provider);

        if ($key === null) {
            return null;
        }

        return new AiConfig(
            $provider,
            ($companyProvider !== null ? $settings->ai_model : null) ?: $platform['providers'][$provider->value]['model'],
            $key,
            $platform['providers'][$provider->value]['base_url'],
            'platform',
        );
    }

    public function isConfigured(): bool
    {
        return $this->resolve() !== null;
    }

    /** Providers the company can pick without its own key. @return list<AiProvider> */
    public function platformProviders(): array
    {
        $platform = $this->platform->ai();

        return array_values(array_filter(AiProvider::cases(), fn (AiProvider $p) => $platform['providers'][$p->value]['enabled'] && $platform['providers'][$p->value]['has_key']));
    }

    public function driver(AiProvider $provider): AiDriver
    {
        return $provider === AiProvider::Anthropic ? app(AnthropicDriver::class) : app(OpenAiCompatibleDriver::class);
    }
}
