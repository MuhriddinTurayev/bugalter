<?php

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Http\Middleware\PlatformTwoFactor;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CompanyOwnerInvitation;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

beforeEach(function () {
    useSqliteTenancy();
    config(['app.single_company' => false]);
    $this->plan = Plan::factory()->create(['name' => 'Business']);
});

afterEach(fn () => cleanUpTenants());

function companyPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Acme Savdo MChJ', 'stir' => '311111111', 'slug' => 'acme', 'plan_id' => test()->plan->id,
        'owner_name' => 'Aziz Karimov', 'owner_email' => 'aziz@acme.test', 'owner_phone' => '+998901112233',
    ], $overrides);
}

describe('creating a company', function () {
    it('provisions a separate database, a domain, the profile and an invited owner, and returns the link', function () {
        Notification::fake();
        signInStaff();

        $response = post(admin('/companies'), companyPayload())->assertRedirect();

        $tenant = Tenant::findOrFail('acme');

        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and($tenant->name)->toBe('Acme Savdo MChJ')
            ->and($tenant->plan_id)->toBe($this->plan->id)
            ->and($tenant->url())->toBe(config('platform.scheme').'://acme.bugalter')
            ->and($tenant->domains()->pluck('domain')->all())->toBe(['acme.bugalter'])
            // its own database file exists and is not the central one
            ->and(file_exists(database_path($tenant->database()->getName())))->toBeTrue()
            ->and($tenant->database()->getName())->toBe('tenantacme.sqlite');

        $response->assertSessionHas('created_url', config('platform.scheme').'://acme.bugalter')
            ->assertSessionHas('invitation_url', fn (string $url) => str_starts_with($url, config('platform.scheme').'://acme.bugalter/invitation/'));

        // Inside the company database: the company profile and exactly one invited owner, no password yet.
        $tenant->run(function () {
            expect(CompanySetting::count())->toBe(1)
                ->and(CompanySetting::first()->name)->toBe('Acme Savdo MChJ')
                ->and(User::count())->toBe(1);

            $owner = User::first();
            expect($owner->role)->toBe(Role::Owner)
                ->and($owner->email)->toBe('aziz@acme.test')
                ->and($owner->password)->toBeNull()
                ->and($owner->invitation_token_hash)->toHaveLength(64)
                ->and($owner->invitation_expires_at->isFuture())->toBeTrue();
        });

        // The invitation was sent and the creation audited.
        Notification::assertSentOnDemand(CompanyOwnerInvitation::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'aziz@acme.test');
        expect(PlatformAuditLog::where('action', 'company.created')->where('tenant_id', 'acme')->exists())->toBeTrue();

        // Nothing of this leaked into the central database: its users table stays empty.
        resetRequestContext();
        expect(User::count())->toBe(0)->and(CompanySetting::count())->toBe(0);
    });

    it('keeps two companies in separate databases', function () {
        Notification::fake();
        signInStaff();

        post(admin('/companies'), companyPayload())->assertRedirect();
        post(admin('/companies'), companyPayload(['name' => 'Beta MChJ', 'stir' => '322222222', 'slug' => 'beta', 'owner_email' => 'b@beta.test']))->assertRedirect();

        $acme = Tenant::findOrFail('acme');
        $beta = Tenant::findOrFail('beta');

        $acme->run(fn () => Contractor::create(['name' => 'Only in Acme']));

        expect($acme->run(fn () => Contractor::count()))->toBe(1)
            ->and($beta->run(fn () => Contractor::count()))->toBe(0)
            ->and($beta->run(fn () => User::first()->email))->toBe('b@beta.test');
    });

    it('lets the owner accept the invitation, set a password and sign in on the company host', function () {
        Notification::fake();
        signInStaff();
        $response = post(admin('/companies'), companyPayload())->assertRedirect();
        $url = session('invitation_url');
        resetRequestContext();

        $path = parse_url($url, PHP_URL_PATH);
        get(company('acme', $path))->assertOk()->assertSee(__('ui.invitation.title'));
        resetRequestContext();

        // weak password refused
        post(company('acme', $path), ['password' => 'weak', 'password_confirmation' => 'weak'])->assertRedirect();
        resetRequestContext();
        expect(Tenant::findOrFail('acme')->run(fn () => User::first()->password))->toBeNull();
        resetRequestContext();

        post(company('acme', $path), ['password' => 'Acme-Owner-2026', 'password_confirmation' => 'Acme-Owner-2026'])->assertRedirect();
        resetRequestContext();

        // the link is single use
        get(company('acme', $path))->assertNotFound();
        resetRequestContext();

        $owner = Tenant::findOrFail('acme')->run(fn () => User::first());
        expect(Hash::check('Acme-Owner-2026', $owner->password))->toBeTrue()
            ->and($owner->invitation_token_hash)->toBeNull();
    });

    it('does not accept an expired or unknown invitation token', function () {
        Notification::fake();
        signInStaff();
        post(admin('/companies'), companyPayload())->assertRedirect();
        resetRequestContext();

        Tenant::findOrFail('acme')->run(fn () => User::first()->forceFill(['invitation_expires_at' => now()->subMinute()])->save());
        $token = Str::afterLast(session('invitation_url'), '/');
        resetRequestContext();

        get(company('acme', "/invitation/$token"))->assertNotFound();
        resetRequestContext();
        get(company('acme', '/invitation/'.str_repeat('x', 48)))->assertNotFound();
    });

    it('resends the invitation with a fresh link that replaces the old one', function () {
        Notification::fake();
        signInStaff();
        post(admin('/companies'), companyPayload())->assertRedirect();
        $old = Str::afterLast(session('invitation_url'), '/');

        post(admin('/companies/acme/invitation'))->assertSessionHas('invitation_url');
        $new = Str::afterLast(session('invitation_url'), '/');
        resetRequestContext();

        expect($new)->not->toBe($old);
        get(company('acme', "/invitation/$old"))->assertNotFound();
        resetRequestContext();
        get(company('acme', "/invitation/$new"))->assertOk();
    });

    it('rejects invalid, reserved and taken slugs and duplicate STIR', function (array $override, string $field) {
        Notification::fake();
        signInStaff();
        post(admin('/companies'), companyPayload())->assertRedirect();

        post(admin('/companies'), companyPayload($override))->assertSessionHasErrors($field);
        expect(Tenant::count())->toBe(1);
    })->with([
        'taken slug' => [['slug' => 'acme', 'stir' => '399999999'], 'slug'],
        'reserved slug' => [['slug' => 'admin', 'stir' => '399999999'], 'slug'],
        'www is reserved' => [['slug' => 'www', 'stir' => '399999999'], 'slug'],
        'invalid chars' => [['slug' => 'Bad_Slug!', 'stir' => '399999999'], 'slug'],
        'leading dash' => [['slug' => '-nope', 'stir' => '399999999'], 'slug'],
        'double dash' => [['slug' => 'a--b', 'stir' => '399999999'], 'slug'],
        'too short' => [['slug' => 'ab', 'stir' => '399999999'], 'slug'],
        'duplicate stir' => [['slug' => 'other', 'stir' => '311111111'], 'stir'],
        'bad stir' => [['slug' => 'other', 'stir' => '123'], 'stir'],
        'owner email required' => [['slug' => 'other', 'stir' => '399999999', 'owner_email' => ''], 'owner_email'],
        'inactive plan' => [['slug' => 'other', 'stir' => '399999999', 'plan_id' => 9999], 'plan_id'],
    ]);

    it('cleans up and reports the failure when provisioning fails halfway', function () {
        Notification::fake();
        signInStaff();
        // Make the database step fail: the sqlite file already exists as a directory.
        mkdir(database_path('tenantboom.sqlite'));

        try {
            post(admin('/companies'), companyPayload(['slug' => 'boom']))->assertSessionHasErrors('slug');
        } finally {
            @rmdir(database_path('tenantboom.sqlite'));
        }

        expect(Tenant::find('boom'))->toBeNull()
            ->and(PlatformAuditLog::where('action', 'company.provisioning_failed')->exists())->toBeTrue();
    });

    it('checks slug availability live', function () {
        Notification::fake();
        signInStaff();
        post(admin('/companies'), companyPayload())->assertRedirect();

        getJson(admin('/companies/slug-check?slug=fresh-one'))->assertOk()->assertJson(['available' => true]);
        getJson(admin('/companies/slug-check?slug=acme'))->assertOk()->assertJson(['available' => false, 'reason' => 'taken']);
        getJson(admin('/companies/slug-check?slug=admin'))->assertJson(['available' => false, 'reason' => 'reserved']);
        getJson(admin('/companies/slug-check?slug=Bad Slug'))->assertJson(['available' => false, 'reason' => 'invalid']);
    });

    it('is only for super admins', function () {
        signInStaff(PlatformRole::Support);

        get(admin('/companies/create'))->assertForbidden();
        post(admin('/companies'), companyPayload())->assertForbidden();
        getJson(admin('/companies/slug-check?slug=x'))->assertForbidden();

        expect(Tenant::count())->toBe(0);
    });
});

describe('managing a company', function () {
    beforeEach(function () {
        Notification::fake();
        $this->staff = signInStaff();
        post(admin('/companies'), companyPayload())->assertRedirect();
        resetRequestContext();
        $this->actingAs($this->staff, 'platform')->withSession([PlatformTwoFactor::SESSION_VERIFIED => true]);
    });

    it('lists companies with link, plan, status and filters', function () {
        $other = Plan::factory()->create(['name' => 'Starter']);
        post(admin('/companies'), companyPayload(['name' => 'Beta MChJ', 'stir' => '322222222', 'slug' => 'beta', 'plan_id' => $other->id, 'owner_email' => 'b@beta.test']));
        Tenant::findOrFail('beta')->update(['status' => TenantStatus::Suspended]);

        get(admin('/companies'))->assertOk()->assertSee('acme.bugalter')->assertSee('beta.bugalter')->assertSee('Business')->assertSee('Starter');
        get(admin('/companies?q=311111'))->assertSee('acme.bugalter')->assertDontSee('beta.bugalter');
        get(admin('/companies?status=suspended'))->assertSee('beta.bugalter')->assertDontSee('acme.bugalter');
        get(admin('/companies?plan_id='.$other->id))->assertSee('beta.bugalter')->assertDontSee('acme.bugalter');
    });

    it('shows company info without any business data', function () {
        Tenant::findOrFail('acme')->run(fn () => Contractor::create(['name' => 'Secret Customer LLC']));

        get(admin('/companies/acme'))->assertOk()
            ->assertSee('Acme Savdo MChJ')->assertSee('acme.bugalter')->assertSee('aziz@acme.test')
            ->assertDontSee('Secret Customer LLC');
    });

    it('changes the plan and audits it', function () {
        $starter = Plan::factory()->create(['name' => 'Starter']);

        patch(admin('/companies/acme/plan'), ['plan_id' => $starter->id])->assertSessionHasNoErrors();

        expect(Tenant::findOrFail('acme')->plan_id)->toBe($starter->id)
            ->and(PlatformAuditLog::where('action', 'company.plan_changed')->where('tenant_id', 'acme')->exists())->toBeTrue();
    });

    it('suspends with a reason and restores', function () {
        // Too short a reason: refused, the company stays active.
        post(admin('/companies/acme/suspend'), ['reason' => 'x'])->assertRedirect();
        expect(Tenant::findOrFail('acme')->status)->toBe(TenantStatus::Active);
        post(admin('/companies/acme/suspend'), ['reason' => 'Unpaid invoices'])->assertSessionHasNoErrors();

        expect(Tenant::findOrFail('acme')->status)->toBe(TenantStatus::Suspended);

        post(admin('/companies/acme/restore'))->assertSessionHasNoErrors();
        expect(Tenant::findOrFail('acme')->status)->toBe(TenantStatus::Active)
            ->and(PlatformAuditLog::whereIn('action', ['company.suspended', 'company.restored'])->count())->toBe(2);
    });

    it('does not let support staff change plan, suspend or resend invitations', function () {
        resetRequestContext();
        signInStaff(PlatformRole::Support);

        patch(admin('/companies/acme/plan'), ['plan_id' => $this->plan->id])->assertForbidden();
        post(admin('/companies/acme/suspend'), ['reason' => 'Because'])->assertForbidden();
        post(admin('/companies/acme/restore'))->assertForbidden();
        post(admin('/companies/acme/invitation'))->assertForbidden();

        get(admin('/companies/acme'))->assertOk();
    });

    it('flags companies with an expired subscription on the dashboard', function () {
        Tenant::findOrFail('acme')->update(['subscription_ends_at' => now()->subDay()]);

        get(admin('/'))->assertOk()->assertSee(__('platform.companies.subscription_expired'))->assertSee('Acme Savdo MChJ');
    });
});
