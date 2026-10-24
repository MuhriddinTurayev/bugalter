<?php

namespace App\Submission\Gateway;

use App\Models\CompanySetting;
use App\Models\TaxReport;
use App\Submission\Signing\SignatureResult;

/** Sends a signed report to the tax authority (or an e-reporting operator). Selected in the platform panel. */
interface TaxAuthorityGateway
{
    public function name(): string;

    /** Validation the authority would apply, run before signing. @return list<string> error messages */
    public function validate(TaxReport $report, CompanySetting $company): array;

    /**
     * Delivers once. Anything short of a definite answer is "unknown", never "rejected": a report the authority
     * may hold must not look resendable. $idempotencyKey is the same for every resend of one approved version.
     */
    public function submit(TaxReport $report, CompanySetting $company, SignatureResult $signature, string $idempotencyKey): GatewayResult;
}
