<?php

namespace App\Http\Controllers;

use App\Actions\DeleteDraftDocument;
use App\Actions\SaveDocument;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Requests\DocumentRequest;
use App\Models\Account;
use App\Models\Contractor;
use App\Models\Document;
use App\Services\Audit;
use App\Services\CurrentCompany;
use App\Services\PlanLimits;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Document::class);

        $type = DocumentType::tryFrom((string) $request->query('type'));
        $status = DocumentStatus::tryFrom((string) $request->query('status'));
        $direction = in_array($request->query('direction'), ['out', 'in'], true) ? $request->query('direction') : null;

        $documents = Document::query()
            ->with('contractor')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($direction, fn ($q) => $q->where('direction', $direction))
            ->when($request->integer('contractor_id'), fn ($q, $id) => $q->where('contractor_id', $id))
            ->when($request->filled('q'), fn ($q) => $q->where('number', 'like', '%'.trim((string) $request->query('q')).'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->query('to')))
            ->latest('issue_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('documents.index', [
            'documents' => $documents,
            'contractors' => Contractor::query()->orderBy('name')->get(['id', 'name']),
            'filters' => compact('type', 'status', 'direction'),
        ]);
    }

    public function create(Request $request, CurrentCompany $company): View
    {
        $this->authorize('create', Document::class);

        $document = new Document([
            'type' => DocumentType::tryFrom((string) $request->query('type')) ?? DocumentType::Invoice,
            'direction' => $request->query('direction') === 'in' ? 'in' : 'out',
            'issue_date' => today(),
            'due_date' => today()->addDays(10),
            'contractor_id' => $request->integer('contractor_id') ?: null,
        ]);

        return view('documents.form', $this->formData($document, $company));
    }

    public function store(DocumentRequest $request, SaveDocument $save, PlanLimits $limits): RedirectResponse
    {
        $limits->ensure(PlanLimits::DOCUMENTS);
        $document = $save($request->documentData(), $request->user());
        app(Audit::class)->record('document.created', ['document' => $document->number, 'total_minor' => $document->total_minor]);

        return redirect()->route('documents.show', $document)->with('status', __('ui.documents.created'));
    }

    public function show(Document $document): View
    {
        $this->authorize('view', $document);

        return view('documents.show', [
            'document' => $document->load('lines', 'contractor', 'creator'),
            'accounts' => Account::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function edit(Document $document, CurrentCompany $company): View
    {
        $this->authorize('update', $document);

        return view('documents.form', $this->formData($document->load('lines'), $company));
    }

    public function update(DocumentRequest $request, Document $document, SaveDocument $save): RedirectResponse
    {
        $save($request->documentData(), $request->user(), $document);
        app(Audit::class)->record('document.updated', ['document' => $document->number, 'total_minor' => $document->total_minor]);

        return redirect()->route('documents.show', $document)->with('status', __('ui.documents.updated'));
    }

    public function destroy(Document $document, DeleteDraftDocument $delete): RedirectResponse
    {
        $this->authorize('delete', $document);

        $result = $delete($document);

        return redirect()->route('documents.index')->with('status', __($result === 'deleted' ? 'ui.documents.deleted' : 'ui.documents.number_kept'));
    }

    /** @return array<string, mixed> */
    private function formData(Document $document, CurrentCompany $company): array
    {
        $lines = $document->exists
            ? $document->lines->map(fn ($line) => [
                'name' => $line->name,
                'unit' => $line->unit,
                'quantity' => rtrim(rtrim($line->quantity, '0'), '.') ?: '0',
                'unit_price' => Money::toInputString($line->unit_price_minor),
                'vat_percent' => (string) ($line->vat_rate_bp / 100),
            ])->all()
            : [[
                'name' => '',
                'unit' => '',
                'quantity' => '1',
                'unit_price' => '',
                'vat_percent' => (string) ($company->settings()->default_vat_rate_bp / 100),
            ]];

        return [
            'document' => $document,
            'initialLines' => old('lines', $lines),
            'contractors' => Contractor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'stir']),
        ];
    }
}
