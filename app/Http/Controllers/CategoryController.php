<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Category::class);

        return view('bank.categories', [
            'categories' => Category::query()->withCount('transactions')->orderBy('type')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(TransactionType::class)],
        ], [], [
            'name' => __('ui.bank.fields.name'),
            'type' => __('ui.bank.fields.type'),
        ]);

        Category::create($data);

        return back()->with('status', __('ui.bank.category_created'));
    }

    /** Categories that are already used are deactivated instead of deleted. */
    public function toggle(Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('status', __('ui.bank.category_updated'));
    }
}
