<?php

namespace App\Submission\Gateway;

use App\Models\CompanySetting;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;
use App\Submission\ReportPayload;
use App\Submission\Signing\SignatureResult;

/**
 * Test gateway: validates the report like the authority would and "accepts" it with a sandbox receipt.
 * Nothing leaves the application. The default until a real gateway is configured in the platform panel.
 */
class SandboxGateway implements TaxAuthorityGateway, VerifiesSubmissions
{
    use BasicValidation;

    public function name(): string
    {
        return 'sandbox';
    }

    public function validate(TaxReport $report, CompanySetting $company): array
    {
        return $this->basicErrors($report, $company);
    }

    public function submit(TaxReport $report, CompanySetting $company, SignatureResult $signature, string $idempotencyKey): GatewayResult
    {
        $errors = $this->validate($report, $company);

        if ($errors !== []) {
            return new GatewayResult('rejected', errors: $errors, raw: ['sandbox' => true]);
        }

        $receipt = 'SBX-'.strtoupper(substr(hash('sha256', $idempotencyKey.$signature->documentHash), 0, 12));

        return new GatewayResult('accepted', $receipt, raw: [
            'sandbox' => true,
            'document_hash' => ReportPayload::hash($report, $company),
            'accepted_at' => now()->toIso8601String(),
        ]);
    }

    /** The sandbox answers synchronously, so a delivery it holds always came back with a receipt. */
    public function verify(TaxReport $report, CompanySetting $company, SubmissionAttempt $attempt): GatewayResult
    {
        return $attempt->receipt_number !== null
            ? new GatewayResult('accepted', $attempt->receipt_number, raw: ['sandbox' => true])
            : new GatewayResult('not_found', raw: ['sandbox' => true]);
    }
}
