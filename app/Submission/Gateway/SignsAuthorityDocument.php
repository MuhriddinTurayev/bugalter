<?php

namespace App\Submission\Gateway;

use App\Enums\Actor;
use App\Models\CompanySetting;
use App\Models\TaxReport;
use App\Portal\AuthorityDocument;
use App\Submission\Signing\SignatureResult;

/**
 * A gateway where the authority builds the document that gets signed (the tax cabinet's own report form),
 * instead of signing this application's payload. SubmissionOrchestrator checks the document against the
 * approved report (AuthorityDocumentCheck) before it signs anything.
 */
interface SignsAuthorityDocument
{
    public function prepareDocument(TaxReport $report, CompanySetting $company, Actor $actor, ?string $actorName): AuthorityDocument;

    public function deliver(TaxReport $report, CompanySetting $company, AuthorityDocument $document, SignatureResult $signature, string $idempotencyKey): GatewayResult;

    /** Gives up a prepared document without signing (a check failed). */
    public function abandon(AuthorityDocument $document): void;
}
