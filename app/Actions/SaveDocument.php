<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentNumbering;
use App\Services\DocumentTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SaveDocument
{
    public function __construct(
        private readonly DocumentNumbering $numbering,
        private readonly DocumentTotals $totals,
    ) {}

    /**
     * Create a document (when $document is null) or replace the header and lines of a draft.
     *
     * @param  array{type: string, direction: string, contractor_id: int, issue_date: string, due_date: ?string, notes: ?string, lines: list<array{name: string, unit: ?string, quantity: string, unit_price_minor: int, vat_rate_bp: int}>}  $data
     */
    public function __invoke(array $data, ?User $user = null, ?Document $document = null): Document
    {
        return DB::transaction(function () use ($data, $user, $document) {
            $type = DocumentType::from($data['type']);
            $issueDate = Carbon::parse($data['issue_date']);

            $attributes = [
                'type' => $type,
                'direction' => $data['direction'],
                'contractor_id' => $data['contractor_id'],
                'issue_date' => $issueDate,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($document === null) {
                $document = new Document($attributes + [
                    'number' => $this->numbering->next($type, $issueDate),
                    'status' => DocumentStatus::Draft,
                    'currency' => 'UZS',
                    'created_by' => $user?->id,
                ]);
                $document->save();
            } else {
                $document->fill($attributes)->save();
                $document->lines()->delete();
            }

            $rows = [];

            foreach ($data['lines'] as $index => $line) {
                $result = $this->totals->line($line['quantity'], $line['unit_price_minor'], $line['vat_rate_bp']);

                $rows[] = [
                    'position' => $index + 1,
                    'name' => $line['name'],
                    'unit' => $line['unit'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'vat_rate_bp' => $line['vat_rate_bp'],
                    'subtotal_minor' => $result['subtotal'],
                    'vat_minor' => $result['vat'],
                    'total_minor' => $result['total'],
                ];
            }

            $document->lines()->createMany($rows);

            $sum = $this->totals->forLines(array_map(fn (array $row) => [
                'quantity' => $row['quantity'],
                'unit_price_minor' => $row['unit_price_minor'],
                'vat_rate_bp' => $row['vat_rate_bp'],
            ], $rows));

            $document->update([
                'subtotal_minor' => $sum['subtotal'],
                'vat_minor' => $sum['vat'],
                'total_minor' => $sum['total'],
            ]);

            return $document->load('lines', 'contractor');
        }, attempts: 5); // retried on deadlock when documents are created concurrently
    }
}
