<?php

namespace App\Submission\Gateway;

use App\Models\CompanySetting;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;
use App\Services\Platform\IntegrationSettings;
use App\Submission\ReportPayload;
use App\Submission\Signing\SignatureResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A configurable HTTP gateway for a tax authority API or an e-reporting operator. Base URL, paths and
 * credentials are entered in the platform panel. This is this application's own contract, not a published
 * Soliq API; an operator with a different format needs a small adapter that maps to it:
 *
 *   POST {base_url}{submit_path}
 *   Authorization: Bearer {client_secret}, X-Client-Id: {client_id}, Idempotency-Key: {key}
 *   { "idempotency_key": "...", "payload": {...}, "document_hash": "...", "signature": "...", "signer": "..." }
 *   -> 2xx { "status": "accepted|rejected|submitted", "receipt": "...", "errors": ["..."] }
 *
 *   GET {base_url}{status_path}?idempotency_key={key}          (optional, enables automatic verification)
 *   -> 2xx { "status": "accepted|rejected|submitted|not_found", "receipt": "...", "errors": ["..."] }
 *
 * The same key is sent again for every resend of one approved version, so the operator can drop duplicates.
 * Only a 4xx is a definite refusal; a timeout, a dropped connection, a 409, 429 or 5xx is "unknown".
 */
class HttpGateway implements TaxAuthorityGateway, VerifiesSubmissions
{
    use BasicValidation;

    public function __construct(private readonly IntegrationSettings $settings) {}

    public function name(): string
    {
        return 'http';
    }

    public function validate(TaxReport $report, CompanySetting $company): array
    {
        $errors = [...$this->basicErrors($report, $company), ...$this->demoErrors($company)];
        $config = $this->settings->gateway();

        if (empty($config['base_url']) || empty($config['submit_path']) || ! $config['has_secret']) {
            $errors[] = __('tax.gateway_errors.not_configured');
        }

        return $errors;
    }

    public function submit(TaxReport $report, CompanySetting $company, SignatureResult $signature, string $idempotencyKey): GatewayResult
    {
        $config = $this->settings->gateway();

        try {
            $response = $this->request($idempotencyKey)->post($this->url((string) $config['submit_path']), [
                'idempotency_key' => $idempotencyKey,
                'payload' => ReportPayload::for($report, $company),
                'document_hash' => $signature->documentHash,
                'signature' => $signature->signature,
                'signer' => $signature->signer,
            ]);
        } catch (ConnectionException $e) {
            // The request may have arrived before the connection dropped.
            return new GatewayResult('unknown', errors: [__('tax.gateway_errors.connection')], raw: ['error' => class_basename($e)]);
        }

        if ($this->uncertain($response)) {
            return new GatewayResult('unknown', errors: [__('tax.gateway_errors.http', ['status' => $response->status()])], raw: ['status' => $response->status()]);
        }

        if (! $response->successful()) {
            return new GatewayResult('rejected', errors: $this->errors($response) ?: [__('tax.gateway_errors.http', ['status' => $response->status()])], raw: ['status' => $response->status()]);
        }

        // A 2xx means the operator holds the report; without a known status it is "submitted", never "accepted".
        $status = in_array($response->json('status'), ['accepted', 'rejected', 'submitted'], true) ? $response->json('status') : 'submitted';

        return new GatewayResult($status, $this->receipt($response), $this->errors($response), ['status' => $response->status()]);
    }

    public function verify(TaxReport $report, CompanySetting $company, SubmissionAttempt $attempt): GatewayResult
    {
        $config = $this->settings->gateway();

        if (empty($config['base_url']) || empty($config['status_path']) || ! $config['has_secret']) {
            return new GatewayResult('unknown', errors: [__('tax.gateway_errors.status_not_configured')]);
        }

        try {
            $response = $this->request($attempt->idempotency_key)->get($this->url((string) $config['status_path']), [
                'idempotency_key' => $attempt->idempotency_key,
            ]);
        } catch (ConnectionException $e) {
            return new GatewayResult('unknown', errors: [__('tax.gateway_errors.connection')], raw: ['error' => class_basename($e)]);
        }

        if (! $response->successful() || ! in_array($response->json('status'), ['accepted', 'rejected', 'submitted', 'not_found'], true)) {
            return new GatewayResult('unknown', errors: [__('tax.gateway_errors.http', ['status' => $response->status()])], raw: ['status' => $response->status()]);
        }

        return new GatewayResult($response->json('status'), $this->receipt($response), $this->errors($response), ['status' => $response->status(), 'verified' => true]);
    }

    private function request(string $idempotencyKey): PendingRequest
    {
        return Http::withToken((string) $this->settings->gatewaySecret())
            ->withHeaders(['X-Client-Id' => (string) $this->settings->gateway()['client_id'], 'Idempotency-Key' => $idempotencyKey])
            ->acceptJson()
            ->timeout($this->settings->gateway()['timeout']);
    }

    private function url(string $path): string
    {
        return $this->settings->gateway()['base_url'].'/'.ltrim($path, '/');
    }

    /** No definite answer: the operator may or may not hold the report. */
    private function uncertain(Response $response): bool
    {
        return $response->serverError() || in_array($response->status(), [408, 409, 425, 429], true);
    }

    private function receipt(Response $response): ?string
    {
        return is_string($response->json('receipt')) ? mb_substr($response->json('receipt'), 0, 80) : null;
    }

    /** @return list<string> */
    private function errors(Response $response): array
    {
        return array_values(array_map(fn ($e) => mb_substr((string) $e, 0, 500), array_filter((array) $response->json('errors', []), 'is_scalar')));
    }
}
