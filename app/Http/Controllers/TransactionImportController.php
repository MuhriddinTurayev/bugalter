<?php

namespace App\Http\Controllers;

use App\Actions\ImportTransactions;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\Audit;
use App\Services\PlanLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TransactionImportController extends Controller
{
    public function create(Request $request, ImportTransactions $import): View|RedirectResponse
    {
        $this->authorize('import', Transaction::class);

        $path = $request->session()->get('transaction_import.path');
        $preview = null;

        if ($path !== null) {
            if (! Storage::disk('local')->exists($path)) {
                $request->session()->forget('transaction_import');
            } else {
                $data = $import->read(Storage::disk('local')->path($path));
                $preview = ['headers' => $data['rows'][0] ?? [], 'rows' => array_slice($data['rows'], 1, 5)];
            }
        }

        return view('bank.import', [
            'preview' => $preview,
            'defaultColumns' => $import->guessColumns($preview['headers'] ?? []),
            'accounts' => Account::query()->where('is_active', true)->orderBy('name')->get(),
            'dateFormats' => ImportTransactions::DATE_FORMATS,
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->authorize('import', Transaction::class);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']], [], [
            'file' => __('ui.bank.import_file'),
        ]);

        $request->session()->put('transaction_import.path', $request->file('file')->store('imports'));

        return redirect()->route('bank.import');
    }

    public function store(Request $request, ImportTransactions $import): RedirectResponse
    {
        $this->authorize('import', Transaction::class);

        $path = $request->session()->get('transaction_import.path');

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return redirect()->route('bank.import');
        }

        $data = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)],
            'date_format' => ['required', Rule::in(ImportTransactions::DATE_FORMATS)],
            'col_date' => ['required', 'integer', 'min:0'],
            'col_amount' => ['required', 'integer', 'min:0', 'different:col_date'],
            'col_description' => ['nullable', 'integer', 'min:0'],
            'col_type' => ['nullable', 'integer', 'min:0'],
            'col_reference' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'account_id' => __('ui.bank.fields.account'),
            'date_format' => __('ui.bank.import_date_format'),
            'col_date' => __('ui.bank.import_columns.date'),
            'col_amount' => __('ui.bank.import_columns.amount'),
        ]);

        $nullable = fn (string $key) => isset($data[$key]) ? (int) $data[$key] : null;

        // The plan limit counts every row the file could add (the header row excluded).
        app(PlanLimits::class)->ensure(PlanLimits::TRANSACTIONS, max(0, count($import->read(Storage::disk('local')->path($path))['rows']) - 1));

        $result = $import(
            Storage::disk('local')->path($path),
            [
                'date' => (int) $data['col_date'],
                'amount' => (int) $data['col_amount'],
                'description' => $nullable('col_description'),
                'type' => $nullable('col_type'),
                'reference' => $nullable('col_reference'),
            ],
            (int) $data['account_id'],
            $data['date_format'],
            $request->user()->id,
        );

        Storage::disk('local')->delete($path);
        $request->session()->forget('transaction_import');
        app(Audit::class)->record('transaction.imported', ['account' => (int) $data['account_id'], 'created' => $result['created'], 'skipped' => $result['skipped']]);

        return redirect()->route('bank.index')
            ->with('status', __('ui.bank.import_done', ['created' => $result['created'], 'skipped' => $result['skipped']]))
            ->with('import_errors', array_slice($result['errors'], 0, 5));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->authorize('import', Transaction::class);

        if ($path = $request->session()->pull('transaction_import.path')) {
            Storage::disk('local')->delete($path);
        }

        return redirect()->route('bank.index');
    }
}
