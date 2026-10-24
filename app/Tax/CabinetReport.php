<?php

namespace App\Tax;

use App\Enums\TaxReportType;
use App\Models\TaxReport;

/**
 * A report whose figures were calculated by the tax authority's own reporting system and read from the real form by the
 * desktop agent (turnover tax of an individual entrepreneur: the system fills the form from e-invoices and online cash
 * registers). Nothing here is invented: every figure is a line of that form, identified by its code. What Bugalter adds is
 * deterministic checking: the form's own arithmetic, the period being over, and a note where the rate differs from the rule table.
 */
final class CabinetReport
{
    /** Line codes of the turnover calculation form (10190_14) that are read, and the Bugalter line each one is shown as. */
    public const CODES = [
        '0101' => 'cabinet_einvoice_income',
        '0102' => 'cabinet_cash_register_income',
        '0103' => 'cabinet_export_income',
        '0104' => 'cabinet_marketplace_income',
        '0105' => 'cabinet_other_income',
        '010' => null,
        '020' => null,
        '030' => 'cabinet_taxable_income',
        '040' => 'cabinet_reduction',
        '050' => 'turnover_base',
        '070' => 'cabinet_turnover_tax',
        '0701' => 'cabinet_tax_reduction',
        '080' => 'payable',
    ];

    /** Largest difference, in tiyin, between the form's tax and base × rate that is still rounding (1 so'm). */
    private const ROUNDING_TOLERANCE = 100;

    /**
     * @param  array<string, mixed>  $draft  {rate_bp, lines: {code: minor}}
     */
    public static function computation(TaxReportType $type, TaxPeriod $period, array $draft, ?TaxRuleBook $rules = null): TaxComputation
    {
        $c = fn (string $code): int => (int) ($draft['lines'][$code] ?? 0);
        $rateBp = (int) ($draft['rate_bp'] ?? 0);

        $lines = [
            ['key' => 'cabinet_einvoice_income', 'amount_minor' => $c('0101')],
            ['key' => 'cabinet_cash_register_income', 'amount_minor' => $c('0102')],
            ['key' => 'cabinet_export_income', 'amount_minor' => $c('0103')],
            ['key' => 'cabinet_marketplace_income', 'amount_minor' => $c('0104')],
            ['key' => 'cabinet_other_income', 'amount_minor' => $c('0105')],
            ['key' => 'cabinet_taxable_income', 'amount_minor' => $c('030'), 'total' => true],
            ['key' => 'cabinet_reduction', 'amount_minor' => $c('040')],
            ['key' => 'turnover_base', 'amount_minor' => $c('050'), 'total' => true],
            ['key' => 'cabinet_turnover_tax', 'amount_minor' => $c('070'), 'params' => ['rate' => self::rateLabel($rateBp)]],
            ['key' => 'cabinet_tax_reduction', 'amount_minor' => $c('0701')],
            ['key' => 'payable', 'amount_minor' => $c('080'), 'total' => true],
        ];

        $issues = (new Issues)->warning('cabinet_computed');

        if (! $period->isClosed()) {
            $issues->error('period_open');
        }

        $consistent = $c('010') + $c('020') === $c('030')
            && $c('030') - $c('040') === $c('050')
            && abs(Percent::of($c('050'), $rateBp) - $c('070')) <= self::ROUNDING_TOLERANCE
            && $c('070') - $c('0701') === $c('080')
            && $rateBp > 0
            && min($c('050'), $c('070'), $c('080')) >= 0;

        if (! $consistent) {
            $issues->error('cabinet_arithmetic');
        }

        if ($rules !== null) {
            try {
                $rule = $rules->rule('turnover.general', $period->end);

                if ($rule->value !== $rateBp) {
                    $issues->warning('cabinet_rate_differs', 0, ['cabinet' => self::rateLabel($rateBp), 'rule' => $rule->valueLabel()]);
                }
            } catch (MissingTaxRule) {
                // No rule to compare with: nothing to say.
            }
        }

        return new TaxComputation($type, $period, $c('050'), $c('070'), $c('080'), $lines, [], $issues->all());
    }

    /** The saved report as a computation again (what the "totals match" gate compares): it is not recalculated from the books. */
    public static function stored(TaxReport $report): TaxComputation
    {
        return new TaxComputation($report->type, $report->period(), $report->base_minor, $report->tax_minor, $report->payable_minor, $report->lines ?? [], [], $report->issues ?? []);
    }

    private static function rateLabel(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',').'%';
    }
}
