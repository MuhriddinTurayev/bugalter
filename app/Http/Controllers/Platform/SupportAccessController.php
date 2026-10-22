<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SupportAccessRequest;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\PlatformStaff;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Platform\SupportAccess;
use App\Support\Money;
use App\Support\PlatformUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The only place in the panel where company business data can be seen. It is read-only (this
 * controller has no write endpoints), needs a written reason, expires, and is audited on both sides.
 */
class SupportAccessController extends Controller
{
    public function __construct(private readonly SupportAccess $support) {}

    public function start(SupportAccessRequest $request, Tenant $tenant): RedirectResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');

        $this->support->start($staff, $tenant, $request->validated('reason'));

        return redirect(PlatformUrl::route('support.show', $tenant))->with('status', __('platform.support.started', ['minutes' => config('platform.support_access_minutes')]));
    }

    public function show(Request $request, Tenant $tenant): View|RedirectResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');

        $session = $this->support->active($staff, $tenant);

        if ($session === null) {
            return redirect(PlatformUrl::route('companies.show', $tenant))->withErrors(['support' => __('platform.support.required')]);
        }

        $tab = in_array($request->query('tab'), ['documents', 'contractors', 'transactions'], true) ? $request->query('tab') : 'documents';
        $this->support->recordView($staff, $tenant, $tab);

        $data = $tenant->run(fn () => [
            'counts' => [
                'documents' => Document::query()->count(),
                'contractors' => Contractor::query()->count(),
                'transactions' => Transaction::query()->count(),
            ],
            // Plain arrays built while the company context is active: models hydrated here remember the
            // company connection, so touching them (even a date) after the context ends would fail.
            'rows' => match ($tab) {
                'contractors' => Contractor::query()->latest('id')->limit(25)->get(['id', 'name', 'stir', 'type', 'is_active'])
                    ->map(fn (Contractor $c) => [
                        'name' => $c->name,
                        'stir' => $c->stir ?? '—',
                        'type' => $c->type->label(),
                        'status' => $c->is_active ? __('ui.common.active') : __('ui.common.inactive'),
                    ])->all(),
                'transactions' => Transaction::query()->with(['account', 'category'])->latest('occurred_at')->limit(25)->get()
                    ->map(fn (Transaction $t) => [
                        'date' => $t->occurred_at->format('d.m.Y H:i'),
                        'account' => $t->account->name,
                        'category' => $t->category?->label() ?? '—',
                        'type' => $t->type->label(),
                        'amount' => Money::format($t->amount_minor),
                    ])->all(),
                default => Document::query()->with('contractor')->latest('issue_date')->latest('id')->limit(25)->get()
                    ->map(fn (Document $d) => [
                        'number' => $d->number,
                        'contractor' => $d->contractor->name,
                        'date' => $d->issue_date->format('d.m.Y'),
                        'status' => $d->status->label(),
                        'tone' => $d->status->tone(),
                        'total' => Money::format($d->total_minor),
                    ])->all(),
            },
        ]);

        return view('platform.support.show', [
            'company' => $tenant,
            'session' => $session,
            'tab' => $tab,
            'counts' => $data['counts'],
            'rows' => $data['rows'],
        ]);
    }

    public function end(Request $request, SupportSession $session): RedirectResponse
    {
        abort_unless($session->staff_id === $request->user('platform')->id, 403);

        $this->support->end($session);

        return redirect(PlatformUrl::route('companies.show', $session->tenant_id))->with('status', __('platform.support.ended'));
    }
}
