<?php

namespace App\Submission\Gateway;

use App\Agent\AgentTasks;
use App\Enums\Actor;
use App\Models\CompanySetting;
use App\Models\DesktopAgent;
use App\Models\SubmissionAttempt;
use App\Models\TaxReport;
use App\Submission\Signing\SignatureResult;
use LogicException;

/**
 * Filing through the tax cabinet (my.soliq.uz) by the company's desktop agent (agent/): a real browser on the
 * accountant's Windows computer, the official E-IMZO app for the signature, the accountant typing the key password
 * in E-IMZO's own window. This application never holds that key or password for this channel.
 *
 * Selected in the platform panel as gateway "desktop". Delivery is asynchronous (App\Agent\AgentTasks); the filled
 * form is compared with the approved figures before the agent may sign.
 */
class DesktopGateway implements DeliversThroughAgent, TaxAuthorityGateway, VerifiesSubmissions
{
    use BasicValidation, VerifiesFromCabinet;

    public function name(): string
    {
        return 'desktop';
    }

    public function validate(TaxReport $report, CompanySetting $company): array
    {
        $errors = [...$this->basicErrors($report, $company), ...$this->demoErrors($company)];

        if (! $company->portal_access) {
            $errors[] = __('tax.portal.errors.no_access');
        }

        if (DesktopAgent::online() === null) {
            $errors[] = __('tax.agent.errors.offline');
        }

        return [...$errors, ...$this->cabinetErrors($report)];
    }

    public function submit(TaxReport $report, CompanySetting $company, SignatureResult $signature, string $idempotencyKey): GatewayResult
    {
        throw new LogicException('The desktop agent delivers asynchronously: SubmissionOrchestrator hands the report to AgentTasks.');
    }

    /** From the cabinet as the agent last read it; when that says nothing yet, the agent is asked to look again. */
    public function verify(TaxReport $report, CompanySetting $company, SubmissionAttempt $attempt): GatewayResult
    {
        $result = $this->verifyFromCabinet($report, $attempt);

        if ($result->status === 'unknown') {
            app(AgentTasks::class)->requestPortalCheck(Actor::System);
        }

        return $result;
    }
}
