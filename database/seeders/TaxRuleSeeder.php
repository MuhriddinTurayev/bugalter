<?php

namespace Database\Seeders;

use App\Models\TaxDeadline;
use App\Models\TaxRule;
use App\Tax\TaxRuleBook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads docs/tax/rules.json into the central tax_rules and tax_deadlines tables. Runs in every environment:
 * these are rules, not demo data. A row whose value changed gets a new version and must be verified again.
 */
class TaxRuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->sync();
    }

    /** @return array{rates: int, deadlines: int, changed: int} */
    public function sync(): array
    {
        $path = base_path('docs/tax/rules.json');
        $data = json_decode((string) @file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['rates'], $data['deadlines'])) {
            throw new RuntimeException("Cannot read the tax rules from $path.");
        }

        $changed = 0;

        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($data, &$changed) {
            foreach ($data['rates'] as $row) {
                // whereDate: some drivers store a date with a time part, so a plain equality would miss the row.
                $rule = TaxRule::query()->where('code', $row['code'])->whereDate('effective_from', $row['effective_from'])->first()
                    ?? new TaxRule(['code' => $row['code'], 'effective_from' => $row['effective_from']]);
                $valueChanged = ! $rule->exists || (int) $rule->value !== (int) $row['value'] || $rule->kind !== $row['kind'];

                $rule->fill([
                    'kind' => $row['kind'],
                    'value' => (int) $row['value'],
                    'effective_to' => $row['effective_to'],
                    'name' => $row['name'],
                    'source' => $row['source'] ?? null,
                ]);

                if ($valueChanged) {
                    $rule->fill([
                        'status' => $row['status'] ?? TaxRule::NEEDS_VERIFICATION,
                        'verified_by' => null,
                        'verified_at' => null,
                        'version' => $rule->exists ? $rule->version + 1 : 1,
                    ]);
                    $changed++;
                }

                $rule->save();
            }

            foreach ($data['deadlines'] as $row) {
                $deadline = TaxDeadline::query()->where('report_type', $row['report_type'])->where('period', $row['period'])
                    ->whereDate('effective_from', $row['effective_from'])->first()
                    ?? new TaxDeadline(['report_type' => $row['report_type'], 'period' => $row['period'], 'effective_from' => $row['effective_from']]);
                $valueChanged = ! $deadline->exists || (int) $deadline->months_after !== (int) $row['months_after'] || (int) $deadline->day !== (int) $row['day'];

                $deadline->fill([
                    'months_after' => (int) $row['months_after'],
                    'day' => (int) $row['day'],
                    'effective_to' => $row['effective_to'],
                    'source' => $row['source'] ?? null,
                ]);

                if ($valueChanged) {
                    $deadline->fill([
                        'status' => $row['status'] ?? TaxRule::NEEDS_VERIFICATION,
                        'verified_by' => null,
                        'verified_at' => null,
                        'version' => $deadline->exists ? $deadline->version + 1 : 1,
                    ]);
                    $changed++;
                }

                $deadline->save();
            }
        });

        app(TaxRuleBook::class)->forget();

        return ['rates' => count($data['rates']), 'deadlines' => count($data['deadlines']), 'changed' => $changed];
    }
}
