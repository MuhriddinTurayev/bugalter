<?php

namespace App\Http\Controllers;

use App\Actions\ImportContractors;
use App\Exports\ContractorsExport;
use App\Models\Contractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContractorTransferController extends Controller
{
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('export', Contractor::class);

        $csv = $request->query('format') === 'csv';

        return Excel::download(
            new ContractorsExport,
            'contractors-'.now()->format('Y-m-d').($csv ? '.csv' : '.xlsx'),
            $csv ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }

    public function import(Request $request, ImportContractors $import): RedirectResponse
    {
        $this->authorize('import', Contractor::class);

        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120']], [], [
            'file' => __('ui.contractors.import_file'),
        ]);

        $result = $import($request->file('file'));

        return redirect()->route('contractors.index')
            ->with('status', __('ui.contractors.import_done', ['created' => $result['created'], 'updated' => $result['updated']]))
            ->with('import_errors', array_slice($result['errors'], 0, 5));
    }
}
