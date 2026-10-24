<?php

namespace App\Portal;

use App\Models\CompanySetting;
use App\Models\SigningKey;
use App\Submission\Gateway\GatewayResult;

/**
 * The company's tax cabinet (my.soliq.uz), reached without an API: a robot drives the portal in a headless
 * browser. The robot never holds a key. Whenever the portal asks E-IMZO for a signature, the robot hands the
 * content back here, and this application decides and signs (App\Portal\PortalSessions, SubmissionOrchestrator).
 *
 * Selected in the platform panel: "sandbox" (no network) or "robot" (the portal-robot service).
 */
interface TaxPortal
{
    public function name(): string;

    /** @return list<string> what is missing before the portal can be used */
    public function configurationErrors(): array;

    /** Opens the login page and returns the challenge the portal wants signed by the company's key. */
    public function startLogin(CompanySetting $company, SigningKey $key): PortalLogin;

    /** Hands the PKCS#7 signature of the challenge to the portal and waits until the cabinet is open. */
    public function completeLogin(PortalLogin $login, string $pkcs7): void;

    /**
     * Reads the cabinet: taxpayer card, personal account balances, submitted reports, messages.
     *
     * @return array<string, mixed> raw data, normalised by PortalSnapshot::normalize()
     */
    public function snapshot(string $session): array;

    /**
     * Fills the report form with the approved figures and stops where the portal asks for the signature.
     *
     * @param  array<string, mixed>  $payload  ReportPayload::for()
     */
    public function prepareReport(string $session, array $payload): AuthorityDocument;

    /** Hands the signature of the portal's own document over and returns the portal's answer. */
    public function sendReport(string $session, AuthorityDocument $document, string $pkcs7): GatewayResult;

    public function close(string $session): void;
}
