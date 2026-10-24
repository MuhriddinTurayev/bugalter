<?php

namespace App\Tax;

use App\Enums\TaxReportType;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\TaxReport;
use Carbon\CarbonImmutable;

/**
 * The company's tax calendar: which reports it owes, for which periods, and when they are due.
 * Built only from the company's regime and the deadline rules.
 */
class TaxObligations
{
    public function __construct(private readonly TaxRuleBook $rules) {}

    /**
     * Recent closed periods and the current one for every report type the company owes.
     *
     * @return list<array{type: TaxReportType, period: TaxPeriod, due_date: ?CarbonImmutable, report: ?TaxReport, closed: bool}>
     */
    public function calendar(CompanySetting $company, int $periodsBack = 3, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $reports = TaxReport::query()->get()->keyBy(fn (TaxReport $r) => $r->type->value.'@'.$r->period_start->toDateString());
        $rows = [];

        // The payroll return is owed only when someone is employed: no active employee, no payroll report to expect.
        $hasEmployees = Employee::query()->where('status', 'active')->exists();

        foreach ($company->regime()->reportTypes((bool) $company->vat_payer) as $type) {
            if ($type === TaxReportType::Payroll && ! $hasEmployees) {
                continue;
            }

            $deadline = $this->rules->deadline($type, $today);

            if ($deadline === null) {
                continue;
            }

            $period = TaxPeriod::containing($deadline->period, $today);
            $periods = [$period];
            for ($i = 0; $i < $periodsBack; $i++) {
                $period = $period->previous();
                $periods[] = $period;
            }

            foreach ($periods as $p) {
                $rule = $this->rules->deadline($type, $p->end) ?? $deadline;
                $rows[] = [
                    'type' => $type,
                    'period' => $p,
                    'due_date' => $rule->dueDateFor($p->end),
                    'report' => $reports[$type->value.'@'.$p->start->toDateString()] ?? null,
                    'closed' => $p->isClosed($today),
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['due_date'], $a['type']->value] <=> [$b['due_date'], $b['type']->value]);

        return $rows;
    }

    /**
     * Closed periods whose report is due soon (or overdue) and not yet sent: what the autopilot prepares.
     *
     * @return list<array{type: TaxReportType, period: TaxPeriod, due_date: ?CarbonImmutable, report: ?TaxReport, closed: bool}>
     */
    public function due(CompanySetting $company, int $withinDays = 31, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return array_values(array_filter(
            $this->calendar($company, 2, $today),
            fn (array $row) => $row['closed']
                && $row['due_date'] !== null
                && $row['due_date']->lte($today->addDays($withinDays))
                && ($row['report'] === null || ! $row['report']->status->isFinal()),
        ));
    }
}
