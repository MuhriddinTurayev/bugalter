<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class DocumentPdfController extends Controller
{
    public function __invoke(Document $document, CurrentCompany $company): Response
    {
        $this->authorize('view', $document);

        return Pdf::loadView('documents.pdf', [
            'document' => $document->load('lines', 'contractor'),
            'company' => $company->settings(),
        ])->setPaper('a4')->download($document->number.'.pdf');
    }
}
