<?php

namespace App\Http\Controllers\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Models\Plan;
use App\Models\PlatformStaff;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\CompanyDatabaseSize;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\SupportAccess;
use App\Services\Platform\TenantProvisioningService;
use App\Support\PlatformUrl;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CompanyController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function index(Request $request, CompanyDatabaseSize $sizes): View
    {
        $status = TenantStatus::tryFrom((string) $request->query('status'));
        $term = trim((string) $request->query('q'));

        $companies = Tenant::query()
            ->with('plan')
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->when($request->integer('plan_id'), fn ($q, $id) => $q->where('plan_id', $id))
            ->when($request->query('problem') === '1', fn ($q) => $q->where('subscription_ends_at', '<', now()))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('stir', 'like', $like)->orWhere('id', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('platform.companies.index', [
            'companies' => $companies,
            'sizes' => $sizes->forTenants($companies->items()),
            'plans' => Plan::query()->orderBy('name')->get(),
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        return view('platform.companies.create', [
            'plans' => Plan::query()->where('is_active', true)->orderBy('name')->get(),
            'domain' => config('platform.domain'),
        ]);
    }

    public function slugCheck(Request $request, TenantProvisioningService $provisioning): JsonResponse
    {
        $result = $provisioning->slugAvailability((string) $request->query('slug'));

        return response()->json($result + ['message' => $result['available']
            ? __('platform.companies.slug_available')
            : __('platform.companies.slug_'.$result['reason'])]);
    }

    public function store(StoreCompanyRequest $request, TenantProvisioningService $provisioning): RedirectResponse
    {
        try {
            $result = $provisioning->provision($request->validated(), $request->user('platform'));
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            report($e);

            return back()->withInput()->withErrors(['slug' => __('platform.companies.provisioning_failed')]);
        }

        return redirect(PlatformUrl::route('companies.show', $result['tenant']))
            ->with('status', __('platform.companies.created'))
            ->with('created_url', $result['url'])
            ->with('invitation_url', $result['invitation_url']);
    }

    public function show(Request $request, Tenant $tenant, CompanyDatabaseSize $sizes, SupportAccess $support): View
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');

        // Only metadata about the company is read here; no business data (that needs a support session).
        $meta = null;

        try {
            $meta = $tenant->run(fn () => [
                'members' => User::query()->count(),
                'invitation_pending' => User::query()->whereNotNull('invitation_token_hash')->exists(),
            ]);
        } catch (Throwable) {
            // The company database is unreachable; the page still renders without these two figures.
        }

        return view('platform.companies.show', [
            'company' => $tenant->load('plan'),
            'meta' => $meta,
            'size' => $sizes->forTenants(collect([$tenant]))[$tenant->id] ?? null,
            'plans' => Plan::query()->where('is_active', true)->orderBy('name')->get(),
            'supportSession' => $support->active($staff, $tenant),
            'minutes' => config('platform.support_access_minutes'),
            'recentSessions' => SupportSession::query()->with('staff')->where('tenant_id', $tenant->id)->latest('started_at')->limit(5)->get(),
        ]);
    }

    public function changePlan(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)]]);
        $old = $tenant->plan_id;

        $tenant->update(['plan_id' => $data['plan_id']]);
        $this->audit->record('company.plan_changed', $tenant->id, null, ['from' => $old, 'to' => (int) $data['plan_id']], $request->user('platform'));

        return back()->with('status', __('platform.companies.plan_changed'));
    }

    /** Sets (or clears) the subscription end date. After it, the company is read-only until extended. */
    public function subscription(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['subscription_ends_at' => ['nullable', 'date']]);
        $old = $tenant->subscription_ends_at?->toDateString();

        $tenant->update(['subscription_ends_at' => isset($data['subscription_ends_at']) ? CarbonImmutable::parse($data['subscription_ends_at'])->endOfDay() : null]);
        $this->audit->record('company.subscription_changed', $tenant->id, null, ['from' => $old, 'to' => $data['subscription_ends_at'] ?? null], $request->user('platform'));

        return back()->with('status', __('platform.companies.subscription_saved'));
    }

    public function suspend(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $tenant->update(['status' => TenantStatus::Suspended]);
        $this->audit->record('company.suspended', $tenant->id, $data['reason'], [], $request->user('platform'));

        return back()->with('status', __('platform.companies.suspended'));
    }

    public function restore(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->update(['status' => TenantStatus::Active]);
        $this->audit->record('company.restored', $tenant->id, null, [], $request->user('platform'));

        return back()->with('status', __('platform.companies.restored'));
    }

    public function resendInvitation(Request $request, Tenant $tenant, TenantProvisioningService $provisioning): RedirectResponse
    {
        $url = $provisioning->resendInvitation($tenant, $request->user('platform'));

        return back()->with('status', __('platform.companies.invitation_resent'))->with('invitation_url', $url);
    }
}
