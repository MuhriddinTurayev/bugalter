<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\TransactionType;
use App\Models\Document;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DocumentStatusController extends Controller
{
    public function __invoke(Request $request, Document $document): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(DocumentStatus::class)],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('is_active', true)],
        ]);

        $next = DocumentStatus::from($data['status']);

        $this->authorize($next === DocumentStatus::Cancelled ? 'cancel' : 'changeStatus', $document);

        if (! $document->status->canTransitionTo($next)) {
            return back()->withErrors(['status' => __('ui.documents.errors.transition')]);
        }

        DB::transaction(function () use ($document, $next, $data, $request) {
            $document->update([
                'status' => $next,
                'paid_at' => $next === DocumentStatus::Paid ? now() : $document->paid_at,
            ]);

            // Paying a document with a chosen account also books the money movement.
            if ($next === DocumentStatus::Paid && ! empty($data['account_id'])) {
                Transaction::create([
                    'account_id' => $data['account_id'],
                    'contractor_id' => $document->contractor_id,
                    'document_id' => $document->id,
                    'type' => $document->isIncoming() ? TransactionType::Expense : TransactionType::Income,
                    'amount_minor' => $document->total_minor,
                    'occurred_at' => now(),
                    'description' => $document->number,
                    'status' => 'completed',
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        return back()->with('status', __('ui.documents.status_changed', ['status' => $next->label()]));
    }
}
