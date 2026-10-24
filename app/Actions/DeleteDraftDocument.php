<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentSequence;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Removing a draft must not leave a gap in the numbering. Only the latest number of its sequence is really
 * deleted (and the counter steps back); an older draft is cancelled instead, so its number stays used.
 */
class DeleteDraftDocument
{
    public function __construct(private readonly Audit $audit) {}

    /** @return 'deleted'|'cancelled' */
    public function __invoke(Document $document): string
    {
        return DB::transaction(function () use ($document) {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);

            [$year, $number] = $this->parse($document->number);

            $sequence = $year === null ? null : DocumentSequence::query()
                ->where('type', $document->type->value)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence !== null && $number !== null && $number < $sequence->last_number) {
                $document->update(['status' => DocumentStatus::Cancelled]);
                $this->audit->record('document.draft_cancelled', ['document' => $document->number], reason: __('ui.documents.number_kept'));

                return 'cancelled';
            }

            if ($sequence !== null && $number === $sequence->last_number) {
                $sequence->update(['last_number' => $number - 1]);
            }

            $this->audit->record('document.deleted', ['document' => $document->number, 'total_minor' => $document->total_minor]);
            $document->delete();

            return 'deleted';
        });
    }

    /** "INV-2026-0042" => [2026, 42] */
    private function parse(string $number): array
    {
        return preg_match('/-(\d{4})-(\d+)$/', $number, $m) ? [(int) $m[1], (int) $m[2]] : [null, null];
    }
}
