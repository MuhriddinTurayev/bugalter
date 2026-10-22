<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlanRequest;
use App\Models\Plan;
use App\Services\Platform\PlatformAudit;
use App\Support\PlatformUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PlanController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function index(): View
    {
        return view('platform.plans.index', ['plans' => Plan::query()->withCount('tenants')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('platform.plans.form', ['plan' => new Plan(['billing_period' => 'month', 'is_active' => true])]);
    }

    public function store(PlanRequest $request): RedirectResponse
    {
        $plan = Plan::create($request->planData());
        $this->audit->record('plan.created', null, null, ['plan' => $plan->key, 'price_minor' => $plan->price_minor], $request->user('platform'));

        return redirect(PlatformUrl::route('plans.index'))->with('status', __('platform.plans.saved'));
    }

    public function edit(Plan $plan): View
    {
        return view('platform.plans.form', compact('plan'));
    }

    public function update(PlanRequest $request, Plan $plan): RedirectResponse
    {
        $before = $plan->only(['name', 'price_minor', 'billing_period', 'limits', 'is_active']);
        $plan->update($request->planData());

        $this->audit->record('plan.updated', null, null, ['plan' => $plan->key, 'before' => $before, 'after' => $plan->only(array_keys($before))], $request->user('platform'));

        return redirect(PlatformUrl::route('plans.index'))->with('status', __('platform.plans.saved'));
    }
}
