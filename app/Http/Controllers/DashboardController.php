<?php

namespace App\Http\Controllers;

use App\Ai\AiSettings;
use App\Http\Requests\DashboardCardRequest;
use App\Services\CurrentCompany;
use App\Services\DashboardMetrics;
use App\Tax\TaxObligations;
use App\Tax\TaxRuleBook;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, CurrentCompany $company, TaxObligations $obligations, TaxRuleBook $rules, AiSettings $ai): View
    {
        // The next three reports that are not sent yet, by due date.
        $upcoming = $request->user()->can('view-reports') && $rules->isLoaded()
            ? collect($obligations->calendar($company->settings(), 1))
                ->filter(fn (array $row) => $row['report'] === null || ! $row['report']->status->isFinal())
                ->take(3)
                ->values()
                ->all()
            : [];

        return view('dashboard.index', [
            'defaultFrom' => now()->startOfMonth()->subMonths(5)->toDateString(),
            'defaultTo' => now()->toDateString(),
            'upcomingTaxes' => $upcoming,
            'aiProvider' => $ai->resolve()?->provider->label(),
        ]);
    }

    public function card(DashboardCardRequest $request, DashboardMetrics $metrics, string $card): JsonResponse
    {
        [$from, $to] = $request->range();

        $data = match ($card) {
            'balance' => $metrics->balance(),
            'weekly' => $metrics->weekly(),
            'trend' => $metrics->trend($from, $to, $request->input('mode', 'monthly')),
            'cashflow' => $metrics->cashflow($from, $to, $request->input('series', 'income')),
            'payments' => $metrics->payments(),
            'receivables' => $metrics->receivables(),
            default => abort(404),
        };

        return response()->json($data);
    }
}
