<?php

namespace App\Portal;

use App\Models\CompanySetting;
use App\Models\SigningKey;
use App\Services\Platform\IntegrationSettings;
use App\Submission\Gateway\GatewayResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * An external portal-robot service (not part of this repository): a headless browser on my.soliq.uz with an E-IMZO
 * stand-in that hands every signing request back to this application. Protocol (Bearer token):
 *
 *   POST   /sessions                       {taxpayer, certificate}  -> {session, challenge (base64)}
 *   POST   /sessions/{s}/login             {pkcs7}                   -> {ok}
 *   GET    /sessions/{s}/snapshot                                    -> {snapshot}
 *   POST   /sessions/{s}/reports           {payload}                 -> {document_id, content (base64), fields}
 *   POST   /sessions/{s}/reports/{d}/sign  {pkcs7}                   -> {status, receipt, errors}
 *   DELETE /sessions/{s}
 *
 * Only certificate metadata (serial, owner, validity) goes to the robot, never the key or its password.
 */
class RobotPortal implements TaxPortal
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    public function name(): string
    {
        return 'robot';
    }

    public function configurationErrors(): array
    {
        $config = $this->settings->portal();

        return empty($config['url']) || ! $config['has_token'] ? [__('tax.portal.errors.robot_not_configured')] : [];
    }

    public function startLogin(CompanySetting $company, SigningKey $key): PortalLogin
    {
        $json = $this->call('post', '/sessions', [
            'portal' => 'my.soliq.uz',
            'taxpayer' => ['tin' => $company->stir, 'name' => $company->name],
            'certificate' => [
                'serial_number' => $key->serial_number,
                'owner_name' => $key->owner_name,
                'owner_tin' => $key->owner_tin,
                'valid_from' => $key->valid_from?->toDateString(),
                'valid_to' => $key->valid_to->toDateString(),
            ],
        ]);

        $challenge = base64_decode((string) ($json['challenge'] ?? ''), true);

        if (! is_string($json['session'] ?? null) || $challenge === false || $challenge === '') {
            throw new PortalException(__('tax.portal.errors.robot_protocol'));
        }

        return new PortalLogin($json['session'], $challenge);
    }

    public function completeLogin(PortalLogin $login, string $pkcs7): void
    {
        $this->call('post', '/sessions/'.rawurlencode($login->session).'/login', ['pkcs7' => $pkcs7]);
    }

    public function snapshot(string $session): array
    {
        $snapshot = $this->call('get', '/sessions/'.rawurlencode($session).'/snapshot')['snapshot'] ?? null;

        return is_array($snapshot) ? $snapshot : throw new PortalException(__('tax.portal.errors.robot_protocol'));
    }

    public function prepareReport(string $session, array $payload): AuthorityDocument
    {
        $json = $this->call('post', '/sessions/'.rawurlencode($session).'/reports', ['payload' => $payload]);
        $content = base64_decode((string) ($json['content'] ?? ''), true);

        if (! is_string($json['document_id'] ?? null) || $content === false || $content === '' || ! is_array($json['fields'] ?? null)) {
            throw new PortalException(__('tax.portal.errors.robot_protocol'));
        }

        $fields = array_map(fn ($v) => is_scalar($v) ? $v : null, $json['fields']);

        return new AuthorityDocument($session, $json['document_id'], $content, $fields);
    }

    public function sendReport(string $session, AuthorityDocument $document, string $pkcs7): GatewayResult
    {
        $json = $this->call('post', '/sessions/'.rawurlencode($session).'/reports/'.rawurlencode($document->id).'/sign', ['pkcs7' => $pkcs7]);
        $status = in_array($json['status'] ?? null, ['accepted', 'rejected', 'submitted'], true) ? $json['status'] : 'submitted';
        $errors = array_values(array_map(fn ($e) => mb_substr((string) $e, 0, 500), array_filter((array) ($json['errors'] ?? []), 'is_scalar')));

        return new GatewayResult($status, is_string($json['receipt'] ?? null) ? mb_substr($json['receipt'], 0, 80) : null, $errors, [
            'portal' => 'my.soliq.uz',
            'document_hash' => $document->hash(),
        ]);
    }

    public function close(string $session): void
    {
        try {
            $this->request()->timeout(15)->delete($this->url('/sessions/'.rawurlencode($session)));
        } catch (ConnectionException) {
            // The robot drops idle sessions by itself.
        }
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, array $body = []): array
    {
        try {
            /** @var Response $response */
            $response = $method === 'get' ? $this->request()->get($this->url($path)) : $this->request()->post($this->url($path), $body);
        } catch (ConnectionException) {
            throw new PortalException(__('tax.portal.errors.robot_unreachable'));
        }

        if (! $response->successful()) {
            // The robot reports portal-side failures as {"error": "..."}; only that text is kept.
            $error = is_string($response->json('error')) ? mb_substr($response->json('error'), 0, 300) : 'HTTP '.$response->status();

            throw new PortalException(__('tax.portal.errors.robot_failed', ['error' => $error]));
        }

        $json = $response->json();

        return is_array($json) ? $json : throw new PortalException(__('tax.portal.errors.robot_protocol'));
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) $this->settings->portalToken())
            ->acceptJson()
            ->timeout($this->settings->portal()['timeout']);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->settings->portal()['url'], '/').$path;
    }
}
