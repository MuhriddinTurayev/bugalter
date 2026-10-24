<?php

namespace App\Http\Controllers;

use App\Enums\ContractorType;
use App\Http\Requests\ContractorRequest;
use App\Models\Contractor;
use App\Services\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContractorController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Contractor::class);

        $status = $request->query('status', 'active');
        $type = ContractorType::tryFrom((string) $request->query('type'));

        $contractors = Contractor::query()
            ->search($request->query('q'))
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('contractors.index', compact('contractors', 'status', 'type'));
    }

    public function create(): View
    {
        $this->authorize('create', Contractor::class);

        return view('contractors.form', ['contractor' => new Contractor(['type' => ContractorType::Customer])]);
    }

    public function store(ContractorRequest $request, Audit $audit): RedirectResponse
    {
        $contractor = Contractor::create($request->validated());
        $audit->record('contractor.created', ['contractor' => $contractor->id, 'name' => $contractor->name]);

        return redirect()->route('contractors.index')->with('status', __('ui.contractors.created'));
    }

    public function edit(Contractor $contractor): View
    {
        $this->authorize('update', $contractor);

        return view('contractors.form', compact('contractor'));
    }

    public function update(ContractorRequest $request, Contractor $contractor, Audit $audit): RedirectResponse
    {
        $contractor->update($request->validated());
        $audit->record('contractor.updated', ['contractor' => $contractor->id, 'name' => $contractor->name, 'changed' => array_keys($contractor->getChanges())]);

        return redirect()->route('contractors.index')->with('status', __('ui.contractors.updated'));
    }

    /** Deactivate or reactivate; contractors with documents are never deleted. */
    public function toggle(Contractor $contractor, Audit $audit): RedirectResponse
    {
        $this->authorize('toggle', $contractor);

        $contractor->update(['is_active' => ! $contractor->is_active]);
        $audit->record($contractor->is_active ? 'contractor.activated' : 'contractor.deactivated', ['contractor' => $contractor->id, 'name' => $contractor->name]);

        return back()->with('status', __($contractor->is_active ? 'ui.contractors.activated' : 'ui.contractors.deactivated'));
    }
}
