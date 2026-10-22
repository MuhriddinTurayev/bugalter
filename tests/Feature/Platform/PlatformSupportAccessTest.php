<?php

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Http\Middleware\PlatformTwoFactor;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PlatformStaff;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\SupportAccess;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

const SUPPORT_REASON = 'Customer ticket #123: check invoice numbers';

beforeEach(function () {
    Notification::fake();
    useSqliteTenancy();
    config(['app.single_company' => false]);

    $plan = Plan::factory()->create();
    $this->tenant = app(TenantProvisioningService::class)->provision([
        'name' => 'Acme MChJ', 'stir' => null, 'slug' => 'acme', 'plan_id' => $plan->id,
        'owner_name' => 'Owner', 'owner_email' => 'owner@acme.test', 'owner_phone' => null,
    ])['tenant'];

    // Some business data inside the company that the panel must not show without a support session.
    $this->tenant->run(function () {
        $contractor = Contractor::create(['name' => 'Confidential Customer LLC', 'type' => 'customer']);
        Document::factory()->create(['contractor_id' => $contractor->id, 'number' => 'INV-2026-4242']);
    });

    resetRequestContext();
});

afterEach(fn () => cleanUpTenants());

function asStaff(PlatformRole $role = PlatformRole::Support): PlatformStaff
{
    resetRequestContext();

    return signInStaff($role);
}

describe('without a support session the panel shows no company data', function () {
    it('shows nothing on the company page', function (PlatformRole $role) {
        asStaff($role);

        get(admin('/companies/acme'))->assertOk()
            ->assertDontSee('Confidential Customer LLC')->assertDontSee('INV-2026-4242');
    })->with([PlatformRole::Support, PlatformRole::SuperAdmin]);

    it('refuses the read-only data page and sends the user back to the company page', function (PlatformRole $role) {
        asStaff($role);

        $response = get(admin('/companies/acme/support'));

        $response->assertRedirect(route('platform.companies.show', 'acme'));
        expect((string) $response->getContent())->not->toContain('INV-2026-4242');
        get(admin('/companies/acme/support?tab=contractors'))->assertRedirect();
        get(admin('/companies/acme/support?tab=transactions'))->assertRedirect();
    })->with([PlatformRole::Support, PlatformRole::SuperAdmin]);

    it('shows no business data on the dashboard, company list, plans or audit pages', function () {
        asStaff(PlatformRole::SuperAdmin);

        foreach (['/', '/companies', '/plans', '/audit-log', '/staff'] as $path) {
            get(admin($path))->assertOk()->assertDontSee('Confidential Customer LLC')->assertDontSee('INV-2026-4242');
        }
    });
});

describe('starting support access', function () {
    it('requires a written reason of a minimum length', function (?string $reason) {
        asStaff();

        post(admin('/companies/acme/support-access'), ['reason' => $reason])->assertRedirect();

        expect(SupportSession::count())->toBe(0)
            ->and(PlatformAuditLog::where('action', 'support_access.started')->exists())->toBeFalse();
        $this->tenant->run(fn () => expect(AuditLog::count())->toBe(0));
    })->with([[null], [''], ['   '], ['too short'], ['123456789']]);

    it('starts a time-limited session, audited on both sides', function () {
        $staff = asStaff();

        post(admin('/companies/acme/support-access'), ['reason' => SUPPORT_REASON])
            ->assertRedirect(route('platform.support.show', 'acme'));

        $session = SupportSession::firstOrFail();
        expect($session->staff_id)->toBe($staff->id)
            ->and($session->tenant_id)->toBe('acme')
            ->and($session->reason)->toBe(SUPPORT_REASON)
            ->and($session->started_at->diffInMinutes($session->expires_at))->toBe(30.0)
            ->and($session->isActive())->toBeTrue();

        $platform = PlatformAuditLog::where('action', 'support_access.started')->firstOrFail();
        expect($platform->staff_name)->toBe($staff->name)->and($platform->tenant_id)->toBe('acme')->and($platform->reason)->toBe(SUPPORT_REASON);

        // The company owner can see it in the company's own audit log.
        $entry = $this->tenant->run(fn () => AuditLog::where('action', 'support_access.started')->firstOrFail());
        expect($entry->actor_type)->toBe('platform')->and($entry->actor_name)->toBe($staff->name)->and($entry->reason)->toBe(SUPPORT_REASON);
    });

    it('uses the configured duration', function () {
        config(['platform.support_access_minutes' => 5]);
        asStaff();

        post(admin('/companies/acme/support-access'), ['reason' => SUPPORT_REASON]);

        $session = SupportSession::firstOrFail();
        expect($session->started_at->diffInMinutes($session->expires_at))->toBe(5.0);
    });
});

describe('during an active session', function () {
    beforeEach(function () {
        $this->staff = asStaff();
        post(admin('/companies/acme/support-access'), ['reason' => SUPPORT_REASON]);
    });

    it('shows company data read-only, with the reason and a countdown', function () {
        get(admin('/companies/acme/support'))->assertOk()
            ->assertSee('INV-2026-4242')->assertSee(__('platform.support.readonly'))->assertSee(SUPPORT_REASON);
        get(admin('/companies/acme/support?tab=contractors'))->assertOk()->assertSee('Confidential Customer LLC');
    });

    it('audits every view on both sides', function () {
        get(admin('/companies/acme/support?tab=documents'));
        get(admin('/companies/acme/support?tab=contractors'));

        expect(PlatformAuditLog::where('action', 'support_access.viewed')->count())->toBe(2);

        $views = $this->tenant->run(fn () => AuditLog::where('action', 'support_access.viewed')->get());
        expect($views)->toHaveCount(2)->and($views->pluck('details.page')->all())->toBe(['documents', 'contractors']);
    });

    it('is read-only: the panel has no way to change company data', function () {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'platform.support.'));

        // Only: start (POST, reason), show (GET), end (DELETE, a platform-side record).
        expect($routes->map(fn ($r) => $r->getName().' '.implode('|', array_diff($r->methods(), ['HEAD'])))->sort()->values()->all())
            ->toBe(['platform.support.end DELETE', 'platform.support.show GET', 'platform.support.start POST']);

        // And a write attempt against the data page changes nothing.
        $this->post(admin('/companies/acme/support'), ['name' => 'Hacked'])->assertStatus(405);
        $this->tenant->run(fn () => expect(Contractor::where('name', 'Hacked')->exists())->toBeFalse());
    });

    it('expires after the configured time and then shows nothing again', function () {
        get(admin('/companies/acme/support'))->assertOk();

        $this->travel(31)->minutes();

        $response = get(admin('/companies/acme/support'));
        $response->assertRedirect(route('platform.companies.show', 'acme'));
        expect((string) $response->getContent())->not->toContain('INV-2026-4242');
        expect(app(SupportAccess::class)->active($this->staff, $this->tenant))->toBeNull();

        get(admin('/companies/acme'))->assertDontSee('INV-2026-4242')->assertDontSee('Confidential Customer LLC');
    });

    it('is still valid just before it expires', function () {
        $this->travel(29)->minutes();

        get(admin('/companies/acme/support'))->assertOk();
    });

    it('can be ended early, and then data is hidden again', function () {
        $session = SupportSession::firstOrFail();

        delete(admin('/support-sessions/'.$session->id))->assertRedirect();

        expect($session->fresh()->ended_at)->not->toBeNull()
            ->and(PlatformAuditLog::where('action', 'support_access.ended')->exists())->toBeTrue();
        $this->tenant->run(fn () => expect(AuditLog::where('action', 'support_access.ended')->exists())->toBeTrue());

        get(admin('/companies/acme/support'))->assertRedirect();
    });

    it('belongs to the staff member who started it: a colleague gets nothing and cannot end it', function () {
        $session = SupportSession::firstOrFail();

        asStaff(PlatformRole::Support);

        get(admin('/companies/acme/support'))->assertRedirect(route('platform.companies.show', 'acme'));
        delete(admin('/support-sessions/'.$session->id))->assertForbidden();
        expect($session->fresh()->ended_at)->toBeNull();
    });

    it('is limited to the company it was opened for', function () {
        $plan = Plan::first();
        app(TenantProvisioningService::class)->provision([
            'name' => 'Other MChJ', 'stir' => null, 'slug' => 'other', 'plan_id' => $plan->id,
            'owner_name' => 'O', 'owner_email' => 'o@other.test', 'owner_phone' => null,
        ]);
        resetRequestContext();
        $this->actingAs($this->staff, 'platform')->withSession([PlatformTwoFactor::SESSION_VERIFIED => true]);

        get(admin('/companies/other/support'))->assertRedirect(route('platform.companies.show', 'other'));
    });

    it('starting again replaces the previous session', function () {
        post(admin('/companies/acme/support-access'), ['reason' => 'Second reason for the same company']);

        expect(SupportSession::count())->toBe(2)
            ->and(SupportSession::whereNull('ended_at')->count())->toBe(1);
    });
});

it('lets the company owner see platform support access in the company audit log', function () {
    $staff = asStaff();
    post(admin('/companies/acme/support-access'), ['reason' => SUPPORT_REASON]);
    get(admin('/companies/acme/support?tab=documents'));
    resetRequestContext();

    // Sign the company owner in on the company host and open the audit page.
    $owner = $this->tenant->run(function () {
        $owner = User::first();
        $owner->forceFill(['password' => 'Acme-Owner-2026', 'invitation_token_hash' => null])->save();

        return $owner;
    });
    $this->actingAs($owner);

    get(company('acme', '/settings/audit'))->assertOk()
        ->assertSee($staff->name)->assertSee(SUPPORT_REASON)->assertSee(__('ui.audit.actions.support_access.started'))
        ->assertSee(__('ui.audit.platform'));

    expect($owner->role)->toBe(Role::Owner);
});

it('keeps both audit logs append-only', function () {
    asStaff();
    post(admin('/companies/acme/support-access'), ['reason' => SUPPORT_REASON]);

    $platform = PlatformAuditLog::firstOrFail();
    expect(fn () => $platform->update(['reason' => 'edited']))->toThrow(LogicException::class)
        ->and(fn () => $platform->delete())->toThrow(LogicException::class);

    $company = $this->tenant->run(fn () => AuditLog::firstOrFail());
    expect(fn () => $this->tenant->run(fn () => $company->update(['reason' => 'edited'])))->toThrow(LogicException::class)
        ->and(fn () => $this->tenant->run(fn () => $company->delete()))->toThrow(LogicException::class);
});

it('lets only the company owner role read the company audit log', function () {
    $this->tenant->run(function () {
        $cashier = User::factory()->cashier()->create();
        $this->cashier = $cashier;
    });
    resetRequestContext();
    $this->actingAs($this->cashier);

    get(company('acme', '/settings/audit'))->assertForbidden();
});

it('knows nothing of a company it cannot find', function () {
    asStaff();

    get(admin('/companies/does-not-exist'))->assertNotFound();
    post(admin('/companies/does-not-exist/support-access'), ['reason' => SUPPORT_REASON])->assertNotFound();
    expect(Tenant::find('does-not-exist'))->toBeNull();
});
