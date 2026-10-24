<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\TransactionType;
use App\Models\Document;
use App\Models\Transaction;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DocumentStatusController extends Controller
{
    public function __invoke(Request $request, Document $document, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(DocumentStatus::class)],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('is_active', true)],
        ]);

        $next = DocumentStatus::from($data['status']);

        $this->authorize($next === DocumentStatus::Cancelled ? 'cancel' : 'changeStatus', $document);

        // The row is locked and re-read inside the transaction: a double click (or two people) cannot move the
        // same document twice, so "paid" never books the payment twice.
        $changed = DB::transaction(function () use ($document, $next, $data, $request, $audit) {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->id);

            if (! $locked->status->canTransitionTo($next)) {
                return false;
            }

            $from = $locked->status;
            $locked->update([
                'status' => $next,
                'paid_at' => $next === DocumentStatus::Paid ? now() : $locked->paid_at,
            ]);

            $transaction = null;

            // Paying a document with a chosen account also books the money movement.
            if ($next === DocumentStatus::Paid && ! empty($data['account_id'])) {
                $transaction = Transaction::create([
                    'account_id' => $data['account_id'],
                    'contractor_id' => $locked->contractor_id,
                    'document_id' => $locked->id,
                    'type' => $locked->isIncoming() ? TransactionType::Expense : TransactionType::Income,
                    'amount_minor' => $locked->total_minor,
                    'occurred_at' => now(),
                    'description' => $locked->number,
                    'status' => 'completed',
                    'created_by' => $request->user()->id,
                ]);
            }

            $audit->record('document.status_changed', [
                'document' => $locked->number,
                'from' => $from->value,
                'to' => $next->value,
                'transaction' => $transaction?->id,
            ]);

            return true;
        });

        if (! $changed) {
            return back()->withErrors(['status' => __('ui.documents.errors.transition')]);
        }

        return back()->with('status', __('ui.documents.status_changed', ['status' => $next->label()]));
    }
}
