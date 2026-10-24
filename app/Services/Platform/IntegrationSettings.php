<?php

namespace App\Services\Platform;

use App\Enums\AiProvider;
use App\Models\PlatformSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Platform-wide integration settings entered in the panel: AI providers, the tax authority gateway, the signer
 * service and the tax cabinet robot. Secrets (API keys, client secrets, tokens) are stored encrypted and are never sent back to
 * the browser; the panel only shows whether one is set.
 */
class IntegrationSettings
{
    public const AI = 'integrations.ai';

    public const GATEWAY = 'integrations.tax_gateway';

    public const SIGNER = 'integrations.signer';

    public const PORTAL = 'integrations.tax_portal';

    /** @return array{default: ?string, providers: array<string, array{enabled: bool, model: string, base_url: string, has_key: bool}>} */
    public function ai(): array
    {
        $stored = PlatformSetting::read(self::AI, []);
        $providers = [];

        foreach (AiProvider::cases() as $provider) {
            $row = $stored['providers'][$provider->value] ?? [];
            $providers[$provider->value] = [
                'enabled' => (bool) ($row['enabled'] ?? false),
                'model' => (string) (($row['model'] ?? '') ?: $provider->defaultModel()),
                'base_url' => (string) (($row['base_url'] ?? '') ?: $provider->defaultBaseUrl()),
                'has_key' => ! empty($row['api_key']),
            ];
        }

        return ['default' => $stored['default'] ?? null, 'providers' => $providers];
    }

    public function aiKey(AiProvider $provider): ?string
    {
        $stored = PlatformSetting::read(self::AI, []);

        return $this->decrypt($stored['providers'][$provider->value]['api_key'] ?? null);
    }

    /**
     * @param  array{enabled?: bool, model?: ?string, base_url?: ?string}  $values
     * @param  string|null  $apiKey  a new key; null keeps the stored one, '' removes it
     */
    public function saveAiProvider(AiProvider $provider, array $values, ?string $apiKey): void
    {
        $stored = PlatformSetting::read(self::AI, []);
        $row = $stored['providers'][$provider->value] ?? [];

        $row['enabled'] = (bool) ($values['enabled'] ?? false);
        $row['model'] = trim((string) ($values['model'] ?? '')) ?: null;
        $row['base_url'] = rtrim(trim((string) ($values['base_url'] ?? '')), '/') ?: null;

        if ($apiKey !== null) {
            $row['api_key'] = $apiKey === '' ? null : Crypt::encryptString($apiKey);
        }

        $stored['providers'][$provider->value] = $row;
        PlatformSetting::write(self::AI, $stored);
    }

    public function setDefaultAiProvider(?AiProvider $provider): void
    {
        $stored = PlatformSetting::read(self::AI, []);
        $stored['default'] = $provider?->value;
        PlatformSetting::write(self::AI, $stored);
    }

    /** @return array{driver: string, base_url: ?string, submit_path: ?string, status_path: ?string, client_id: ?string, has_secret: bool, timeout: int} */
    public function gateway(): array
    {
        $stored = PlatformSetting::read(self::GATEWAY, []);

        return [
            'driver' => $stored['driver'] ?? 'sandbox',
            'base_url' => $stored['base_url'] ?? null,
            'submit_path' => $stored['submit_path'] ?? null,
            'status_path' => $stored['status_path'] ?? null,
            'client_id' => $stored['client_id'] ?? null,
            'has_secret' => ! empty($stored['client_secret']),
            'timeout' => (int) ($stored['timeout'] ?? 30),
        ];
    }

    public function gatewaySecret(): ?string
    {
        return $this->decrypt(PlatformSetting::read(self::GATEWAY, [])['client_secret'] ?? null);
    }

    /** @param  array<string, mixed>  $values */
    public function saveGateway(array $values, ?string $secret): void
    {
        $stored = PlatformSetting::read(self::GATEWAY, []);

        $stored = array_merge($stored, [
            'driver' => $values['driver'],
            'base_url' => rtrim((string) ($values['base_url'] ?? ''), '/') ?: null,
            'submit_path' => $values['submit_path'] ?? null,
            'status_path' => $values['status_path'] ?? null,
            'client_id' => $values['client_id'] ?? null,
            'timeout' => (int) ($values['timeout'] ?? 30),
        ]);

        if ($secret !== null) {
            $stored['client_secret'] = $secret === '' ? null : Crypt::encryptString($secret);
        }

        PlatformSetting::write(self::GATEWAY, $stored);
    }

    /** @return array{engine: string, url: ?string, has_token: bool} */
    public function signer(): array
    {
        $stored = PlatformSetting::read(self::SIGNER, []);

        return [
            'engine' => $stored['engine'] ?? 'sandbox',
            'url' => $stored['url'] ?? null,
            'has_token' => ! empty($stored['token']),
        ];
    }

    public function signerToken(): ?string
    {
        return $this->decrypt(PlatformSetting::read(self::SIGNER, [])['token'] ?? null);
    }

    public function saveSigner(string $engine, ?string $url, ?string $token): void
    {
        $stored = PlatformSetting::read(self::SIGNER, []);
        $stored['engine'] = $engine;
        $stored['url'] = rtrim((string) $url, '/') ?: null;

        if ($token !== null) {
            $stored['token'] = $token === '' ? null : Crypt::encryptString($token);
        }

        PlatformSetting::write(self::SIGNER, $stored);
    }

    /** @return array{driver: string, url: ?string, has_token: bool, timeout: int} the tax cabinet robot */
    public function portal(): array
    {
        $stored = PlatformSetting::read(self::PORTAL, []);

        return [
            'driver' => $stored['driver'] ?? 'sandbox',
            'url' => $stored['url'] ?? null,
            'has_token' => ! empty($stored['token']),
            'timeout' => (int) ($stored['timeout'] ?? 180),
        ];
    }

    public function portalToken(): ?string
    {
        return $this->decrypt(PlatformSetting::read(self::PORTAL, [])['token'] ?? null);
    }

    public function savePortal(string $driver, ?string $url, int $timeout, ?string $token): void
    {
        $stored = PlatformSetting::read(self::PORTAL, []);
        $stored['driver'] = $driver;
        $stored['url'] = rtrim((string) $url, '/') ?: null;
        $stored['timeout'] = $timeout;

        if ($token !== null) {
            $stored['token'] = $token === '' ? null : Crypt::encryptString($token);
        }

        PlatformSetting::write(self::PORTAL, $stored);
    }

    private function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null; // APP_KEY changed: the secret has to be entered again
        }
    }
}
