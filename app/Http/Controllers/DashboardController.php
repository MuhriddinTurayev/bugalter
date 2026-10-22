<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardCardRequest;
use App\Services\DashboardMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard.index', [
            'defaultFrom' => now()->startOfMonth()->subMonths(5)->toDateString(),
            'defaultTo' => now()->toDateString(),
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
