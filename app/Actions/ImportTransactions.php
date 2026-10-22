<?php

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ImportTransactions
{
    /** Date formats the user can choose from. */
    public const DATE_FORMATS = ['d.m.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'];

    /**
     * Read a delimited text file; returns the delimiter and all rows (first row = header).
     *
     * @return array{delimiter: string, rows: list<list<string>>}
     */
    public function read(string $path): array
    {
        $handle = fopen($path, 'rb');
        $first = (string) fgets($handle);
        rewind($handle);

        if (str_starts_with($first, "\xEF\xBB\xBF")) {
            fread($handle, 3);
        }

        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn (string $d) => substr_count($first, $d))->first();

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null] || array_filter($row, fn ($cell) => trim((string) $cell) !== '') === []) {
                continue;
            }
            $rows[] = array_map(fn ($cell) => trim((string) $cell), $row);
        }
        fclose($handle);

        return ['delimiter' => $delimiter, 'rows' => $rows];
    }

    /**
     * @param  array{date: int, amount: int, description: ?int, type: ?int, reference: ?int}  $map  column indexes
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function __invoke(string $path, array $map, int $accountId, string $dateFormat, int $userId): array
    {
        $data = $this->read($path);
        $rows = array_slice($data['rows'], 1);

        $result = ['created' => 0, 'skipped' => 0, 'errors' => []];
        $records = [];

        foreach ($rows as $index => $cells) {
            $line = $index + 2;

            try {
                $date = Carbon::createFromFormat('!'.$dateFormat, $cells[$map['date']] ?? '');
            } catch (\Throwable) {
                $date = false;
            }

            $rawAmount = trim((string) ($cells[$map['amount']] ?? ''));
            $negative = str_starts_with($rawAmount, '-') || str_starts_with($rawAmount, '−') || str_starts_with($rawAmount, '(');
            $minor = Money::parseToMinor(trim($rawAmount, "-−+() \u{00A0}"));

            if (! $date || $minor === null || $minor === 0) {
                $result['skipped']++;
                $result['errors'][] = __('ui.bank.import_row_error', ['row' => $line]);

                continue;
            }

            $type = $negative ? TransactionType::Expense : TransactionType::Income;

            if ($map['type'] !== null) {
                $typeCell = mb_strtolower((string) ($cells[$map['type']] ?? ''));
                $type = $this->typeFromCell($typeCell) ?? $type;
            }

            $records[] = [
                'account_id' => $accountId,
                'type' => $type->value,
                'amount_minor' => $minor,
                'occurred_at' => $date->setTime(12, 0),
                'description' => $map['description'] !== null ? mb_substr((string) ($cells[$map['description']] ?? ''), 0, 255) ?: null : null,
                'reference' => $map['reference'] !== null ? mb_substr((string) ($cells[$map['reference']] ?? ''), 0, 60) ?: null : null,
                'status' => 'completed',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($records, &$result) {
            foreach (array_chunk($records, 200) as $chunk) {
                Transaction::query()->insert($chunk);
            }
            $result['created'] = count($records);
        });

        return $result;
    }

    private function typeFromCell(string $cell): ?TransactionType
    {
        return match (true) {
            in_array($cell, ['income', 'credit', 'kirim', 'приход', 'доход'], true) => TransactionType::Income,
            in_array($cell, ['expense', 'debit', 'chiqim', 'расход'], true) => TransactionType::Expense,
            default => null,
        };
    }
}
