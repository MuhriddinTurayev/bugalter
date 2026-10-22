<?php

namespace App\Http\Controllers;

use App\Models\Contractor;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $term = trim((string) $request->query('q'));

        $contractors = $term === '' ? collect() : Contractor::query()->search($term)->orderBy('name')->limit(10)->get();
        $documents = $term === '' ? collect() : Document::query()
            ->with('contractor')
            ->where(fn ($q) => $q
                ->where('number', 'like', '%'.$term.'%')
                ->orWhereHas('contractor', fn ($c) => $c->search($term)))
            ->latest('issue_date')
            ->limit(10)
            ->get();

        return view('search.index', compact('term', 'contractors', 'documents'));
    }
}
