<?php

namespace App\Submission\Signing;

use App\Models\SigningKey;
use App\Services\Platform\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The only place a signature is made (PLAN section 5, "Signing vault and Signer service"). It receives a
 * document hash and a key reference and returns a signature. It is never exposed to the AI.
 *
 * Engines (chosen in the platform panel):
 *  - sandbox: an HMAC over the hash with the decrypted key. Proves the vault round trip; NOT a legal signature.
 *  - remote:  a separate signer service (e.g. an E-IMZO server deployment) that holds its own vault access;
 *             only the hash (or, for signContent, the authority's document) and the key reference leave this
 *             application. Uzbek ERI keys use O'zDSt 1092, which OpenSSL cannot sign with, so a real signature
 *             always comes from such a service.
 */
class Signer
{
    public function __construct(private readonly KeyVault $vault, private readonly IntegrationSettings $settings) {}

    public function sign(string $documentHash, SigningKey $key): SignatureResult
    {
        $config = $this->settings->signer();

        if ($config['engine'] === 'remote') {
            return $this->remote($documentHash, $key, $config['url']);
        }

        $signature = $this->vault->use($key, fn (string $contents, string $password) => base64_encode(
            hash_hmac('sha256', $documentHash, $contents.$password, true),
        ));

        return new SignatureResult('sandbox', $documentHash, $signature, $key->id, legallyValid: false);
    }

    /**
     * Signs exact bytes the tax authority asked for (an attached PKCS#7, as E-IMZO's create_pkcs7 makes it):
     * the cabinet's login challenge or the portal's own report document. The caller has already decided that
     * these bytes may be signed (PortalSessions for a login, SubmissionOrchestrator for a report).
     *
     * @param  'login'|'report'  $purpose
     */
    public function signContent(string $content, SigningKey $key, string $purpose): SignatureResult
    {
        $config = $this->settings->signer();
        $hash = hash('sha256', $content);

        if ($config['engine'] !== 'remote') {
            return $this->sign($hash, $key);
        }

        if ($config['url'] === null) {
            throw new RuntimeException(__('autopilot.errors.signer_not_configured'));
        }

        $response = Http::withToken((string) $this->settings->signerToken())
            ->acceptJson()
            ->timeout(30)
            ->post($config['url'].'/sign-content', [
                'key_reference' => $key->id,
                'key_fingerprint' => $key->fingerprint,
                'key_serial' => $key->serial_number,
                'purpose' => $purpose,
                'content' => base64_encode($content),
                'content_hash' => $hash,
                'tenant' => tenant()?->getTenantKey(),
            ]);

        if (! $response->successful() || ! is_string($response->json('signature'))) {
            throw new RuntimeException(__('autopilot.errors.signer_failed', ['status' => $response->status()]));
        }

        return new SignatureResult('remote', $hash, $response->json('signature'), $key->id, legallyValid: true);
    }

    private function remote(string $documentHash, SigningKey $key, ?string $url): SignatureResult
    {
        if ($url === null) {
            throw new RuntimeException(__('autopilot.errors.signer_not_configured'));
        }

        $response = Http::withToken((string) $this->settings->signerToken())
            ->acceptJson()
            ->timeout(30)
            ->post($url.'/sign', [
                'key_reference' => $key->id,
                'key_fingerprint' => $key->fingerprint,
                'key_serial' => $key->serial_number,
                'document_hash' => $documentHash,
                'tenant' => tenant()?->getTenantKey(),
            ]);

        if (! $response->successful() || ! is_string($response->json('signature'))) {
            throw new RuntimeException(__('autopilot.errors.signer_failed', ['status' => $response->status()]));
        }

        return new SignatureResult('remote', $documentHash, $response->json('signature'), $key->id, legallyValid: true);
    }
}
