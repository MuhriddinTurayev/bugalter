<?php

namespace App\Actions;

use App\Enums\ContractorType;
use App\Imports\SpreadsheetRows;
use App\Models\Contractor;
use App\Support\ContractorColumns;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ImportContractors
{
    /**
     * Import contractors from an xlsx/csv file. A row with a STIR that already exists updates that contractor.
     *
     * @return array{created: int, updated: int, errors: list<string>}
     */
    public function __invoke(UploadedFile $file): array
    {
        $sheet = Excel::toArray(new SpreadsheetRows, $file)[0] ?? [];

        $header = array_shift($sheet) ?? [];
        $columns = array_map(fn ($heading) => ContractorColumns::fieldFor((string) $heading), $header);

        $result = ['created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($sheet as $index => $cells) {
            $row = [];
            foreach ($columns as $position => $field) {
                if ($field !== null) {
                    $row[$field] = trim((string) ($cells[$position] ?? '')) ?: null;
                }
            }

            if (array_filter($row) === []) {
                continue;
            }

            $row['type'] = $row['type'] ?? ContractorType::Customer->value;
            $line = $index + 2;

            $validator = Validator::make($row, [
                'name' => ['required', 'string', 'max:255'],
                'stir' => ['nullable', 'regex:/^(\d{9}|\d{14})$/'],
                'type' => ['required', 'in:'.implode(',', array_column(ContractorType::cases(), 'value'))],
                'phone' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:255'],
                'address' => ['nullable', 'string', 'max:255'],
                'bank_name' => ['nullable', 'string', 'max:255'],
                'bank_account' => ['nullable', 'regex:/^\d{20}$/'],
                'mfo' => ['nullable', 'regex:/^\d{5}$/'],
            ]);

            if ($validator->fails()) {
                $result['errors'][] = __('ui.contractors.import_row_error', [
                    'row' => $line,
                    'message' => $validator->errors()->first(),
                ]);

                continue;
            }

            $data = $validator->validated();
            $existing = isset($data['stir']) ? Contractor::query()->where('stir', $data['stir'])->first() : null;

            if ($existing) {
                $existing->update($data);
                $result['updated']++;
            } else {
                Contractor::create($data);
                $result['created']++;
            }
        }

        return $result;
    }
}
