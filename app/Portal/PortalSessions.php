<?php

namespace App\Portal;

use App\Enums\Actor;
use App\Models\SignatureLog;
use App\Models\SigningKey;
use App\Services\CurrentCompany;
use App\Submission\Signing\Signer;
use Closure;
use Throwable;

/**
 * Signs in to the tax cabinet with the company's vault key. Allowed only while the owner's consent
 * (company_settings.portal_access) is on. The login challenge comes from outside, so it is signed only when it
 * looks like one: short printable text. A robot cannot pass a report or a contract off as a login challenge.
 * Every login signature is written to the signature log with purpose "login".
 */
class PortalSessions
{
    private const MAX_CHALLENGE_BYTES = 512;

    public function __construct(
        private readonly TaxPortal $portal,
        private readonly Signer $signer,
        private readonly CurrentCompany $company,
    ) {}

    /** Opens a signed-in session. The caller must close() it. */
    public function open(Actor $actor, ?string $actorName = null): string
    {
        $settings = $this->company->settings();

        if (! $settings->portal_access) {
            throw new PortalException(__('tax.portal.errors.no_access'));
        }

        if (($errors = $this->portal->configurationErrors()) !== []) {
            throw new PortalException(implode(' ', $errors));
        }

        $key = SigningKey::usable() ?? throw new PortalException(__('tax.portal.errors.no_key'));
        $login = $this->portal->startLogin($settings, $key);

        try {
            if (strlen($login->challenge) > self::MAX_CHALLENGE_BYTES || ! preg_match('/^[\x20-\x7E]+$/', $login->challenge)) {
                throw new PortalException(__('tax.portal.errors.bad_challenge'));
            }

            try {
                $signature = $this->signer->signContent($login->challenge, $key, 'login');
            } catch (PortalException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new PortalException(__('tax.portal.errors.login_signing', ['error' => $e->getMessage()]));
            }

            SignatureLog::create([
                'signing_key_id' => $key->id,
                'tax_report_id' => null,
                'signer' => $signature->signer,
                'document_hash' => $signature->documentHash,
                'signature_hash' => hash('sha256', $signature->signature),
                'actor_type' => $actor->value,
                'actor_name' => $actorName ?? $actor->label(),
                'approval_ref' => 'portal_access:'.($settings->portal_access_by ?? '-'),
                'purpose' => 'login',
            ]);

            $this->portal->completeLogin($login, $signature->signature);
        } catch (Throwable $e) {
            $this->portal->close($login->session);

            throw $e;
        }

        return $login->session;
    }

    public function close(string $session): void
    {
        $this->portal->close($session);
    }

    /**
     * @template T
     *
     * @param  Closure(string): T  $callback
     * @return T
     */
    public function within(Actor $actor, ?string $actorName, Closure $callback): mixed
    {
        $session = $this->open($actor, $actorName);

        try {
            return $callback($session);
        } finally {
            $this->close($session);
        }
    }
}
