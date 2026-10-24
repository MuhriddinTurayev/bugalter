<?php

namespace App\Submission\Gateway;

use App\Enums\Actor;
use App\Models\CompanySetting;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;
use App\Portal\AuthorityDocument;
use App\Portal\PortalException;
use App\Portal\PortalSessions;
use App\Portal\TaxPortal;
use App\Submission\ReportPayload;
use App\Submission\Signing\SignatureResult;
use LogicException;
use Throwable;

/**
 * Submission through the tax cabinet (my.soliq.uz) by the portal robot: sign in, fill the report form with the
 * approved figures, sign the portal's document, send. Selected in the platform panel as gateway "portal".
 */
class PortalGateway implements SignsAuthorityDocument, TaxAuthorityGateway, VerifiesSubmissions
{
    use BasicValidation, VerifiesFromCabinet;

    public function __construct(
        private readonly TaxPortal $portal,
        private readonly PortalSessions $sessions,
    ) {}

    public function name(): string
    {
        return 'portal';
    }

    public function validate(TaxReport $report, CompanySetting $company): array
    {
        $errors = [...[...$this->basicErrors($report, $company), ...$this->demoErrors($company)], ...$this->portal->configurationErrors()];

        if (! $company->portal_access) {
            $errors[] = __('tax.portal.errors.no_access');
        }

        if ($company->signer_mode !== 'vault') {
            $errors[] = __('tax.portal.errors.needs_vault');
        }

        // The cabinet must have been looked at, and must not already hold this report: no double filing.
        return [...$errors, ...$this->cabinetErrors($report)];
    }

    public function submit(TaxReport $report, CompanySetting $company, SignatureResult $signature, string $idempotencyKey): GatewayResult
    {
        throw new LogicException('The portal signs its own document: use prepareDocument() and deliver().');
    }

    public function prepareDocument(TaxReport $report, CompanySetting $company, Actor $actor, ?string $actorName): AuthorityDocument
    {
        $session = $this->sessions->open($actor, $actorName);

        try {
            return $this->portal->prepareReport($session, ReportPayload::for($report, $company));
        } catch (Throwable $e) {
            $this->sessions->close($session);

            throw $e;
        }
    }

    public function deliver(TaxReport $report, CompanySetting $company, AuthorityDocument $document, SignatureResult $signature, string $idempotencyKey): GatewayResult
    {
        try {
            return $this->portal->sendReport($document->session, $document, $signature->signature);
        } catch (PortalException $e) {
            // The signature left this application, so the portal may have taken the report. It is never
            // retried automatically: the outcome is "unknown" until a later cabinet check settles it.
            return new GatewayResult('unknown', null, [__('tax.portal.errors.delivery_unknown'), $e->getMessage()], [
                'portal' => $this->portal->name(),
                'document_hash' => $document->hash(),
                'outcome' => 'unknown',
            ]);
        } finally {
            $this->sessions->close($document->session);
        }
    }

    /**
     * What the cabinet showed at a visit that began after this delivery. A report missing from the cabinet's list
     * is not proof that it never arrived (the list may lag), so absence stays "unknown" for a person to settle.
     */
    public function verify(TaxReport $report, CompanySetting $company, SubmissionAttempt $attempt): GatewayResult
    {
        return $this->verifyFromCabinet($report, $attempt);
    }

    public function abandon(AuthorityDocument $document): void
    {
        $this->sessions->close($document->session);
    }
}
