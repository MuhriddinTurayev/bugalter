<?php

namespace App\Submission\Gateway;

use App\Models\CompanySetting;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;

/**
 * A gateway that can tell what the authority holds for a delivery (SubmissionVerifier). Answers accepted,
 * rejected, submitted (still processing), not_found (never received) or unknown (could not find out). It must
 * say not_found only when the authority's own answer says so, never because a list was empty or unreachable.
 */
interface VerifiesSubmissions
{
    public function verify(TaxReport $report, CompanySetting $company, SubmissionAttempt $attempt): GatewayResult;
}
