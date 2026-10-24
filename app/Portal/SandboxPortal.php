<?php

namespace App\Portal;

use App\Enums\TaxReportStatus;
use App\Models\CompanySetting;
use App\Models\SigningKey;
use App\Models\TaxReport;
use App\Services\CurrentCompany;
use App\Submission\Gateway\GatewayResult;

/**
 * A stand-in cabinet with no network: it "shows" the company's own data and accepts every signed report with a
 * sandbox receipt. The default until the robot is configured in the platform panel. Tests replace what it
 * shows with fake().
 */
class SandboxPortal implements TaxPortal
{
    /** @var array<string, mixed>|null */
    private static ?array $fake = null;

    public function __construct(private readonly CurrentCompany $company) {}

    /**
     * What the sandbox cabinet shows instead of the defaults. Keys: taxpayer, balances, reports, messages, and
     * document_fields (values the "filled form" reports back, to simulate a portal that changed a figure).
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fake(?array $data): void
    {
        self::$fake = $data;
    }

    public function name(): string
    {
        return 'sandbox';
    }

    public function configurationErrors(): array
    {
        return [];
    }

    public function startLogin(CompanySetting $company, SigningKey $key): PortalLogin
    {
        return new PortalLogin('sbx-'.bin2hex(random_bytes(8)), 'sandbox-login:'.bin2hex(random_bytes(16)));
    }

    public function completeLogin(PortalLogin $login, string $pkcs7): void
    {
        if (trim($pkcs7) === '') {
            throw new PortalException(__('tax.portal.errors.login_failed'));
        }
    }

    public function snapshot(string $session): array
    {
        $settings = $this->company->settings();

        $defaults = [
            'taxpayer' => [
                'tin' => $settings->stir,
                'name' => $settings->name,
                'vat_payer' => (bool) $settings->vat_payer,
                'regime' => $settings->regime()->value,
            ],
            'balances' => [],
            // The sandbox cabinet knows exactly what this application sent to it.
            'reports' => TaxReport::query()->whereIn('status', [TaxReportStatus::Submitted, TaxReportStatus::Accepted])->get()->map(fn (TaxReport $r) => [
                'type' => $r->type->value,
                'form' => $r->type->value,
                'name' => $r->type->label(),
                'period_start' => $r->period_start->toDateString(),
                'period_end' => $r->period_end->toDateString(),
                'status' => 'accepted',
                'submitted_at' => $r->submitted_at?->toDateString(),
                'receipt' => $r->receipt_number,
            ])->values()->all(),
            'messages' => [],
        ];

        $fake = self::$fake ?? [];
        unset($fake['document_fields']);

        return array_replace($defaults, $fake);
    }

    public function prepareReport(string $session, array $payload): AuthorityDocument
    {
        $fields = [
            'tin' => $payload['taxpayer']['tin'] ?? null,
            'type' => $payload['report']['type'] ?? null,
            'period_start' => $payload['report']['period_start'] ?? null,
            'period_end' => $payload['report']['period_end'] ?? null,
            'base_minor' => $payload['report']['base_minor'] ?? null,
            'tax_minor' => $payload['report']['tax_minor'] ?? null,
            'payable_minor' => $payload['report']['payable_minor'] ?? null,
        ];

        return new AuthorityDocument(
            $session,
            'sbx-doc-'.bin2hex(random_bytes(6)),
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            array_replace($fields, self::$fake['document_fields'] ?? []),
        );
    }

    public function sendReport(string $session, AuthorityDocument $document, string $pkcs7): GatewayResult
    {
        $receipt = 'SBX-'.strtoupper(substr(hash('sha256', $document->hash().$pkcs7), 0, 12));

        return new GatewayResult('accepted', $receipt, raw: ['sandbox' => true, 'portal' => 'sandbox', 'document_hash' => $document->hash()]);
    }

    public function close(string $session): void {}
}
