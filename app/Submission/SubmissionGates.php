<?php

namespace App\Submission;

use App\Models\CompanySetting;
use App\Models\Mandate;
use App\Models\SigningKey;
use App\Models\TaxReport;
use App\Services\Platform\KillSwitch;
use App\Submission\Gateway\DeliversThroughAgent;
use App\Submission\Gateway\TaxAuthorityGateway;
use App\Tax\TaxReportPreparer;

/**
 * Hard-stop gates (PLAN section 5, step 4). They run before signing whatever the mandate or autopilot level
 * says; any failure blocks the submission. Autonomous submissions (mandate) get the stricter set.
 */
class SubmissionGates
{
    public function __construct(
        private readonly TaxReportPreparer $preparer,
        private readonly TaxAuthorityGateway $gateway,
    ) {}

    /** @return list<array{key: string, passed: bool, params?: array<string, scalar>}> */
    public function check(TaxReport $report, CompanySetting $company, bool $checkSigningKey = true): array
    {
        $autonomous = $report->approval_source === 'mandate';
        $results = [];

        // The platform kill switch is checked first, then the owner's own switch (autonomous only).
        if ($autonomous) {
            $results[] = $this->gate('kill_switch', ! KillSwitch::isActive());
            $results[] = $this->gate('company_paused', ! $company->isAutopilotPaused());
        }

        $results[] = $this->gate('approval_valid', $report->isApprovalValid());

        $fresh = $this->preparer->recompute($report);
        $results[] = $this->gate('totals_match', hash_equals($report->version_hash, $fresh->versionHash()));

        $blocking = array_values(array_filter($fresh->issues, fn (array $issue) => $issue['severity'] === 'error'));
        $results[] = $this->gate('data_quality', $blocking === [], ['count' => count($blocking)]);

        if ($autonomous) {
            // Figures the cabinet calculated are approved by a person who has seen them, never by a standing mandate.
            $results[] = $this->gate('cabinet_figures_need_person', $report->source !== 'cabinet');

            $unverified = array_filter($report->rules ?? [], fn (array $rule) => ! ($rule['verified'] ?? false));
            $results[] = $this->gate('rules_verified', $unverified === [], ['count' => count($unverified)]);

            $mandate = $report->mandate_id ? Mandate::query()->find($report->mandate_id) : null;
            $inScope = $mandate !== null && $mandate->isActive() && $mandate->covers($report->type)
                && ! $report->period_start->isBefore($mandate->period_from) && ! $report->period_end->isAfter($mandate->period_to);
            $results[] = $this->gate('mandate_scope', $inScope);
            $results[] = $this->gate('mandate_amount', $mandate !== null && $report->payable_minor <= $mandate->amount_limit_minor);
            $results[] = $this->gate('mandate_change', $mandate !== null && $this->changeWithinLimit($report, $mandate), [
                'limit' => (int) $mandate?->change_limit_percent,
            ]);
        }

        // The desktop agent signs with the official E-IMZO app on the accountant's computer, not with a vault key.
        if ($checkSigningKey && $company->signer_mode === 'vault' && ! $this->gateway instanceof DeliversThroughAgent) {
            $results[] = $this->gate('signing_key', SigningKey::usable() !== null);
        }

        $errors = $this->gateway->validate($report, $company);
        $results[] = $this->gate('authority_validation', $errors === [], ['errors' => implode('; ', $errors)]);

        return $results;
    }

    /** @param  list<array{key: string, passed: bool}>  $results */
    public static function passed(array $results): bool
    {
        return collect($results)->every(fn (array $gate) => $gate['passed']);
    }

    /** Change against the previous period's report, within the mandate's limit (when it sets one). */
    private function changeWithinLimit(TaxReport $report, Mandate $mandate): bool
    {
        if ($mandate->change_limit_percent === null) {
            return true;
        }

        $previous = TaxReport::query()
            ->where('type', $report->type->value)
            ->whereDate('period_start', $report->period()->previous()->start->toDateString())
            ->first();

        if ($previous === null || $previous->payable_minor === 0) {
            return $report->payable_minor === 0 || $previous === null;
        }

        $change = abs($report->payable_minor - $previous->payable_minor) * 100;

        return $change <= $mandate->change_limit_percent * $previous->payable_minor;
    }

    private function gate(string $key, bool $passed, array $params = []): array
    {
        return array_filter(['key' => $key, 'passed' => $passed, 'params' => array_filter($params, fn ($v) => $v !== '' && $v !== 0) ?: null], fn ($v) => $v !== null);
    }
}
