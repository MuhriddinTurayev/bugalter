<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\DocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free, race-safe document numbers per type and year, e.g. INV-2026-0001.
 *
 * Call inside the transaction that saves the document: the row lock is held until commit,
 * so concurrent requests queue up instead of getting the same number, and a failed save
 * rolls the counter back.
 */
class DocumentNumbering
{
    public function next(DocumentType $type, CarbonInterface $date): string
    {
        return DB::transaction(function () use ($type, $date) {
            $year = (int) $date->format('Y');

            $locked = fn () => DocumentSequence::query()
                ->where('type', $type->value)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $sequence = $locked();

            // Only the first document of a type and year creates the row. Concurrent first
            // documents can deadlock here; the caller's transaction is retried on deadlock.
            if ($sequence === null) {
                DocumentSequence::query()->insertOrIgnore([
                    'type' => $type->value,
                    'year' => $year,
                    'last_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $sequence = $locked() ?? throw new \LogicException('Document sequence row is missing.');
            }

            $number = $sequence->last_number + 1;
            $sequence->update(['last_number' => $number]);

            return sprintf('%s-%d-%04d', $type->numberPrefix(), $year, $number);
        });
    }
}
