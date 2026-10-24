<?php

namespace Database\Seeders;

use App\Enums\AiProvider;
use App\Services\Platform\IntegrationSettings;
use Illuminate\Database\Seeder;

/**
 * Local development defaults for the integrations: the sandbox gateway and signer, and the four AI providers
 * with their default models. A provider is enabled only when its key is in the environment (config/ai.php);
 * otherwise keys are entered in the panel (Integrations). No key is ever written in code.
 */
class DemoIntegrationsSeeder extends Seeder
{
    public function run(IntegrationSettings $settings): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoIntegrationsSeeder skipped: it only runs when APP_ENV=local.');

            return;
        }

        $settings->saveGateway(['driver' => 'sandbox', 'timeout' => 30], null);
        $settings->saveSigner('sandbox', null, null);

        $default = null;

        foreach (AiProvider::cases() as $provider) {
            $key = (string) config('ai.seed_keys.'.$provider->value, '');
            $settings->saveAiProvider($provider, ['enabled' => $key !== '', 'model' => null, 'base_url' => null], $key !== '' ? $key : null);
            $default ??= $key !== '' ? $provider : null;
        }

        $settings->setDefaultAiProvider($default);
    }
}
