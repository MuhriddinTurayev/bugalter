<?php

namespace App\Portal;

use App\Enums\TaxReportType;
use App\Models\CompanySetting;
use App\Models\TaxReport;
use App\Tax\TaxPeriod;
use Carbon\CarbonImmutable;

/**
 * Compares the cabinet with the company's own books, deterministically: is the cabinet the right taxpayer,
 * which reports the company owes are already on the portal, which are missing or overdue, and what the
 * personal account shows (debt, penalty, overpayment). Severity: error, warning, info, ok.
 */
class PortalAnalyzer
{
    /**
     * @param  array<string, mixed>  $snapshot  PortalSnapshot::normalize()
     * @param  list<array{type: TaxReportType, period: TaxPeriod, due_date: ?CarbonImmutable, report: ?TaxReport, closed: bool}>  $calendar  TaxObligations::calendar()
     * @return array{findings: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function analyze(array $snapshot, CompanySetting $company, array $calendar, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $findings = [];
        $taxpayer = $snapshot['taxpayer'];

        // A cabinet of another taxpayer: nothing else is looked at, and nothing is prepared from it.
        // The cabinet shows the STIR (company) or, for an entrepreneur, the PINFL the owner linked to this company.
        $identifiers = array_values(array_filter([(string) $company->stir, (string) $company->pinfl]));

        if ($taxpayer['tin'] !== null && ! in_array($taxpayer['tin'], $identifiers, true)) {
            return [
                'findings' => [$this->finding('tin_mismatch', 'error', params: ['portal' => $taxpayer['tin'], 'company' => (string) $company->stir])],
                'summary' => ['tin_mismatch' => 1],
            ];
        }

        if ($taxpayer['vat_payer'] !== null && $taxpayer['vat_payer'] !== (bool) $company->vat_payer) {
            $findings[] = $this->finding('vat_status_mismatch', 'warning', params: ['portal' => $taxpayer['vat_payer'] ? 'yes' : 'no']);
        }

        if ($taxpayer['regime'] !== null && $taxpayer['regime'] !== $company->regime()->value) {
            $findings[] = $this->finding('regime_mismatch', 'warning', params: ['portal' => $taxpayer['regime']]);
        }

        $summary = ['submitted' => 0, 'missing' => 0, 'overdue' => 0, 'rejected' => 0, 'other_reports' => 0, 'unread' => 0,
            'debt_minor' => 0, 'penalty_minor' => 0, 'overpaid_minor' => 0];

        $portalReports = [];
        foreach ($snapshot['reports'] as $row) {
            if ($row['type'] !== null && $row['period_start'] !== null) {
                // Several filings of one period (a correction after a rejection): the most final status wins.
                $key = $row['type'].'@'.$row['period_start'];
                $portalReports[$key] = $this->moreFinal($portalReports[$key] ?? null, $row);
            }
        }

        // A tax whose cabinet reports cover a different period length than Bugalter's calendar (monthly in the cabinet, quarterly
        // in the rules): the calendar cannot be compared with the cabinet, so no "overdue" or "missing" is claimed for it. The
        // rule is for an accountant to check, never silently changed here.
        $mismatched = [];
        foreach ($calendar as $row) {
            $type = $row['type']->value;
            $kinds = collect($snapshot['reports'])->where('type', $type)->map(fn (array $r) => $this->kindOf($r))->filter()->unique();

            if (! isset($mismatched[$type]) && $kinds->isNotEmpty() && ! $kinds->contains($row['period']->kind)) {
                $mismatched[$type] = ['row' => $row, 'cabinet' => (string) $kinds->first()];
            }
        }

        foreach ($mismatched as $type => $info) {
            $findings[] = $this->finding('period_mismatch', 'warning', [
                'type' => $type, 'period_kind' => $info['row']['period']->kind, 'period_start' => $info['row']['period']->start->toDateString(), 'due_date' => null, 'report_id' => null,
            ], ['cabinet' => $info['cabinet']]);

            foreach ($snapshot['reports'] as $r) {
                if ($r['type'] === $type && in_array($r['status'], ['accepted', 'submitted'], true)) {
                    $summary['submitted']++;
                }
            }
        }

        $known = [];
        foreach ($calendar as $row) {
            if (! $row['closed'] || isset($mismatched[$row['type']->value])) {
                continue;
            }

            $key = $row['type']->value.'@'.$row['period']->start->toDateString();
            $known[$key] = true;
            $onPortal = $portalReports[$key] ?? null;
            $base = ['type' => $row['type']->value, 'period_kind' => $row['period']->kind, 'period_start' => $row['period']->start->toDateString(), 'due_date' => $row['due_date']?->toDateString(), 'report_id' => $row['report']?->id];

            if ($onPortal !== null && in_array($onPortal['status'], ['accepted', 'submitted'], true)) {
                $findings[] = $this->finding('submitted', 'ok', $base, ['receipt' => (string) $onPortal['receipt']]);
                $summary['submitted']++;
            } elseif ($onPortal !== null && $onPortal['status'] === 'rejected') {
                $findings[] = $this->finding('rejected', 'error', $base);
                $summary['rejected']++;
            } elseif ($row['due_date'] !== null && $row['due_date']->isBefore($today)) {
                $findings[] = $this->finding('overdue', 'error', $base, ['days' => (int) $row['due_date']->diffInDays($today)]);
                $summary['overdue']++;
            } else {
                $findings[] = $this->finding('missing', 'warning', $base, ['days' => $row['due_date'] ? (int) $today->diffInDays($row['due_date']) : 0]);
                $summary['missing']++;
            }
        }

        foreach ($snapshot['reports'] as $row) {
            if ($row['type'] !== null && isset($mismatched[$row['type']])) {
                continue; // reported on its own (period_mismatch), not as an unknown report
            }

            if ($row['type'] === null || $row['period_start'] === null || ! isset($known[$row['type'].'@'.$row['period_start']])) {
                $summary['other_reports']++;
            }
        }

        if ($summary['other_reports'] > 0) {
            $findings[] = $this->finding('other_reports', 'info', params: ['count' => $summary['other_reports']]);
        }

        foreach ($snapshot['balances'] as $balance) {
            $params = ['tax' => $balance['name'] !== '' ? $balance['name'] : $balance['tax']];

            if ($balance['debt_minor'] > 0) {
                $findings[] = $this->finding('debt', 'warning', params: $params + ['amount_minor' => $balance['debt_minor']]);
                $summary['debt_minor'] += $balance['debt_minor'];
            }

            if ($balance['penalty_minor'] > 0) {
                $findings[] = $this->finding('penalty', 'warning', params: $params + ['amount_minor' => $balance['penalty_minor']]);
                $summary['penalty_minor'] += $balance['penalty_minor'];
            }

            if ($balance['overpaid_minor'] > 0) {
                $findings[] = $this->finding('overpaid', 'info', params: $params + ['amount_minor' => $balance['overpaid_minor']]);
                $summary['overpaid_minor'] += $balance['overpaid_minor'];
            }
        }

        $summary['unread'] = count(array_filter($snapshot['messages'], fn (array $m) => $m['unread']));
        if ($summary['unread'] > 0) {
            $findings[] = $this->finding('unread_messages', 'info', params: ['count' => $summary['unread']]);
        }

        $order = ['error' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3];
        usort($findings, fn (array $a, array $b) => [$order[$a['severity']], $a['due_date'] ?? ''] <=> [$order[$b['severity']], $b['due_date'] ?? '']);

        return ['findings' => $findings, 'summary' => $summary];
    }

    /** @return array<string, mixed> */
    private function finding(string $key, string $severity, array $base = [], array $params = []): array
    {
        return ['key' => $key, 'severity' => $severity] + $base + ['params' => $params];
    }

    /** 'month' or 'quarter' from the first and last day of a cabinet report; null when it is neither (or has no period). */
    private function kindOf(array $report): ?string
    {
        if ($report['period_start'] === null || $report['period_end'] === null) {
            return null;
        }

        $months = (int) round(abs(CarbonImmutable::parse($report['period_start'])->diffInMonths(CarbonImmutable::parse($report['period_end'])->addDay())));

        return match ($months) {
            1 => 'month',
            3 => 'quarter',
            default => null,
        };
    }

    private function moreFinal(?array $current, array $candidate): array
    {
        $rank = ['accepted' => 3, 'submitted' => 2, 'rejected' => 1, 'draft' => 0];

        return $current === null || $rank[$candidate['status']] > $rank[$current['status']] ? $candidate : $current;
    }
}
