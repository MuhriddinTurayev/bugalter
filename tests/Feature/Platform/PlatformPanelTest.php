<?php

use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PlatformSetting;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Notifications\StaffInvitation;
use App\Services\Platform\KillSwitch;
use App\Services\Platform\PlatformAudit;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

describe('plans', function () {
    it('lists plans for every staff role, but only super admins can change them', function () {
        Plan::factory()->create(['name' => 'Gold']);

        signInStaff(PlatformRole::Support);
        get(admin('/plans'))->assertOk()->assertSee('Gold');
        get(admin('/plans/create'))->assertForbidden();
        post(admin('/plans'), ['key' => 'x1', 'name' => 'X', 'billing_period' => 'month'])->assertForbidden();
        expect(Plan::count())->toBe(1);
    });

    it('creates and edits a plan with a price entered in the panel, stored in minor units', function () {
        signInStaff();

        post(admin('/plans'), [
            'key' => 'Pro', 'name' => 'Pro', 'description' => 'For growing companies', 'price' => '1 500 000,50',
            'billing_period' => 'year', 'limits' => ['max_users' => '10', 'max_documents_per_month' => '', 'max_transactions_per_month' => '5000'],
            'is_active' => '1',
        ])->assertRedirect();

        $plan = Plan::firstWhere('key', 'pro');
        expect($plan->price_minor)->toBe(150_000_050)
            ->and($plan->billing_period)->toBe('year')
            ->and($plan->limits)->toBe(['max_users' => 10, 'max_transactions_per_month' => 5000])
            ->and($plan->limit('max_documents_per_month'))->toBeNull()   // blank = unlimited
            ->and($plan->priceLabel())->toContain('1')->and($plan->is_active)->toBeTrue();

        put(admin('/plans/'.$plan->id), ['key' => 'pro', 'name' => 'Pro Max', 'price' => '', 'billing_period' => 'month', 'is_active' => '0'])->assertRedirect();

        $plan->refresh();
        expect($plan->name)->toBe('Pro Max')->and($plan->price_minor)->toBeNull()->and($plan->limits)->toBeNull()->and($plan->is_active)->toBeFalse()
            ->and($plan->priceLabel())->toBe(__('platform.plans.price_not_set'));
        expect(PlatformAuditLog::whereIn('action', ['plan.created', 'plan.updated'])->count())->toBe(2);
    });

    it('validates plans', function (array $override, string $field) {
        signInStaff();
        Plan::factory()->create(['key' => 'taken']);

        post(admin('/plans'), $override + ['key' => 'ok', 'name' => 'Ok', 'billing_period' => 'month'])->assertSessionHasErrors($field);
    })->with([
        'duplicate key' => [['key' => 'taken'], 'key'],
        'bad key' => [['key' => 'Bad Key!'], 'key'],
        'bad price' => [['price' => '12,345'], 'price'],
        'bad period' => [['billing_period' => 'week'], 'billing_period'],
        'negative limit' => [['limits' => ['max_users' => '-1']], 'limits.max_users'],
        'name required' => [['name' => ''], 'name'],
    ]);

    it('never carries a hardcoded price: seeded plans have none', function () {
        (new PlanSeeder)->run();

        expect(Plan::whereNotNull('price_minor')->count())->toBe(0)->and(Plan::count())->toBe(3);
    });
});

describe('staff', function () {
    it('is for super admins only', function () {
        signInStaff(PlatformRole::Support);

        get(admin('/staff'))->assertForbidden();
        post(admin('/staff'), ['name' => 'X', 'email' => 'x@y.test', 'role' => 'support'])->assertForbidden();
    });

    it('invites a member, who sets a password from the link and must then enrol 2FA', function () {
        Notification::fake();
        signInStaff();

        post(admin('/staff'), ['name' => 'Dilnoza', 'email' => 'DILNOZA@bugalter.test', 'role' => 'support'])
            ->assertSessionHas('invitation_url');

        $member = PlatformStaff::firstWhere('email', 'dilnoza@bugalter.test');
        expect($member->role)->toBe(PlatformRole::Support)->and($member->password)->toBeNull()->and($member->hasTwoFactor())->toBeFalse()
            ->and($member->invitation_token_hash)->toHaveLength(64);
        Notification::assertSentOnDemand(StaffInvitation::class);

        $token = Str::afterLast(session('invitation_url'), '/');
        auth('platform')->logout();
        resetRequestContext();

        get(admin("/staff-invitation/$token"))->assertOk();
        post(admin("/staff-invitation/$token"), ['password' => 'weak', 'password_confirmation' => 'weak'])->assertRedirect();
        expect($member->fresh()->password)->toBeNull();

        post(admin("/staff-invitation/$token"), ['password' => 'Brand-New-Pass-2026', 'password_confirmation' => 'Brand-New-Pass-2026'])
            ->assertRedirect(admin('/two-factor/enroll'));

        expect(Hash::check('Brand-New-Pass-2026', $member->fresh()->password))->toBeTrue()->and($member->fresh()->invitation_token_hash)->toBeNull();

        // single use, and unknown tokens are 404
        get(admin("/staff-invitation/$token"))->assertNotFound();
        get(admin('/staff-invitation/'.str_repeat('z', 48)))->assertNotFound();
    });

    it('refuses an expired invitation', function () {
        $member = PlatformStaff::factory()->create();
        $member->forceFill(['password' => null, 'invitation_token_hash' => hash('sha256', 'tok'), 'invitation_expires_at' => now()->subMinute()])->save();

        get(admin('/staff-invitation/tok'))->assertNotFound();
    });

    it('rejects invalid invitations', function (array $override, string $field) {
        signInStaff();
        PlatformStaff::factory()->create(['email' => 'taken@bugalter.test']);

        post(admin('/staff'), $override + ['name' => 'N', 'email' => 'n@bugalter.test', 'role' => 'support'])->assertSessionHasErrors($field);
    })->with([
        'duplicate email' => [['email' => 'taken@bugalter.test'], 'email'],
        'bad role' => [['role' => 'root'], 'role'],
        'bad email' => [['email' => 'nope'], 'email'],
    ]);

    it('disables and enables members, but never yourself or the last super admin', function () {
        $me = signInStaff();
        $other = PlatformStaff::factory()->support()->create();
        $second = PlatformStaff::factory()->create(); // another super admin

        patch(admin('/staff/'.$other->id.'/toggle'))->assertSessionHasNoErrors();
        expect($other->fresh()->is_active)->toBeFalse();
        patch(admin('/staff/'.$other->id.'/toggle'));
        expect($other->fresh()->is_active)->toBeTrue();

        patch(admin('/staff/'.$me->id.'/toggle'))->assertSessionHasErrors('staff');
        expect($me->fresh()->is_active)->toBeTrue();

        // with a second super admin, disabling is possible; then the remaining one is protected
        patch(admin('/staff/'.$second->id.'/toggle'))->assertSessionHasNoErrors();
        $second->refresh();
        expect($second->is_active)->toBeFalse();
    });

    it('protects the last active super admin from losing the role', function () {
        $me = signInStaff();
        $other = PlatformStaff::factory()->create();

        patch(admin('/staff/'.$other->id.'/role'), ['role' => 'support'])->assertSessionHasNoErrors();
        expect($other->fresh()->role)->toBe(PlatformRole::Support);

        // the other is now support, so $me is the only super admin: changing own role is refused
        patch(admin('/staff/'.$me->id.'/role'), ['role' => 'support'])->assertSessionHasErrors('staff');
        expect($me->fresh()->role)->toBe(PlatformRole::SuperAdmin);
        expect(PlatformAuditLog::where('action', 'staff.role_changed')->count())->toBe(1);
    });
});

describe('platform kill switch for autopilot', function () {
    it('is for super admins only', function () {
        signInStaff(PlatformRole::Support);

        get(admin('/kill-switch'))->assertForbidden();
        post(admin('/kill-switch'), ['active' => 1, 'reason' => 'Because of an incident'])->assertForbidden();
        expect(KillSwitch::isActive())->toBeFalse();
    });

    it('requires a reason, turns on and off, and keeps its own audit trail', function () {
        $staff = signInStaff();

        post(admin('/kill-switch'), ['active' => 1, 'reason' => 'short'])->assertSessionHasErrors('reason');
        expect(KillSwitch::isActive())->toBeFalse();

        post(admin('/kill-switch'), ['active' => 1, 'reason' => 'Incident: wrong reports being prepared'])->assertSessionHasNoErrors();

        expect(KillSwitch::isActive())->toBeTrue()
            ->and(KillSwitch::state()['by'])->toBe($staff->name)
            ->and(KillSwitch::state()['reason'])->toBe('Incident: wrong reports being prepared');
        get(admin('/'))->assertSee(__('platform.kill_switch.banner'));
        get(admin('/kill-switch'))->assertOk()->assertSee(__('platform.kill_switch.state_on'));

        post(admin('/kill-switch'), ['active' => 0, 'reason' => 'Incident resolved, resuming'])->assertSessionHasNoErrors();
        expect(KillSwitch::isActive())->toBeFalse();
        get(admin('/'))->assertDontSee(__('platform.kill_switch.banner'));

        $trail = PlatformAuditLog::whereIn('action', ['killswitch.enabled', 'killswitch.disabled'])->orderBy('id')->get();
        expect($trail->pluck('action')->all())->toBe(['killswitch.enabled', 'killswitch.disabled'])
            ->and($trail[0]->reason)->toBe('Incident: wrong reports being prepared')
            ->and($trail[0]->staff_id)->toBe($staff->id);
    });

    it('stores its state centrally so any future autopilot gate can read it', function () {
        expect(KillSwitch::isActive())->toBeFalse();

        KillSwitch::set(true, 'test', 'someone');
        expect(KillSwitch::isActive())->toBeTrue()->and(PlatformSetting::find(KillSwitch::KEY))->not->toBeNull();
    });
});

describe('audit log', function () {
    it('filters by action, staff, company and date', function () {
        $a = signInStaff();
        $b = PlatformStaff::factory()->create(['name' => 'Second Person']);
        app(PlatformAudit::class)->record('company.suspended', 'acme', 'Unpaid', [], $a);
        app(PlatformAudit::class)->record('company.restored', 'beta', null, [], $b);

        get(admin('/audit-log'))->assertOk()->assertSee('acme')->assertSee('beta');
        get(admin('/audit-log?action=company.suspended'))->assertSee('acme')->assertDontSee('beta');
        get(admin('/audit-log?staff_id='.$b->id))->assertSee('beta')->assertDontSee('acme');
        get(admin('/audit-log?tenant_id=acme'))->assertSee('acme')->assertDontSee('beta');
        get(admin('/audit-log?from='.now()->addDay()->toDateString()))->assertDontSee('acme')->assertSee(__('platform.audit.empty'));
    });

    it('shows readable action names, never raw translation keys', function () {
        signInStaff();
        app(PlatformAudit::class)->record('support_access.started', 'acme', 'Reason text here');

        get(admin('/audit-log'))->assertSee(__('platform.audit.actions.support_access.started'))->assertDontSee('platform.audit.actions');
        get(admin('/'))->assertDontSee('platform.audit.actions');
    });
});

describe('the dashboard', function () {
    it('counts companies by status and new ones this month', function () {
        signInStaff();
        $plan = Plan::factory()->create();
        foreach ([['a', 'active'], ['b', 'active'], ['c', 'suspended'], ['d', 'failed']] as [$id, $status]) {
            Tenant::withoutEvents(fn () => Tenant::create(['id' => $id, 'name' => strtoupper($id), 'plan_id' => $plan->id, 'status' => $status]));
        }

        $response = get(admin('/'))->assertOk();
        expect($response->viewData('total'))->toBe(4)
            ->and($response->viewData('byStatus')->all())->toBe(['provisioning' => 0, 'active' => 2, 'suspended' => 1, 'failed' => 1])
            ->and($response->viewData('newThisMonth'))->toBe(4)
            ->and($response->viewData('problems'))->toHaveCount(2);
    });
});

describe('creating the first admin', function () {
    it('asks for the password interactively and creates a super admin that must enrol 2FA', function () {
        $this->artisan('platform:create-admin', ['email' => 'First@Bugalter.test', 'name' => 'First Admin'])
            ->expectsQuestion('Password (at least 12 characters, mixed case and a number)', 'Very-Strong-Pass-2026')
            ->expectsQuestion('Repeat the password', 'Very-Strong-Pass-2026')
            ->assertSuccessful();

        $admin = PlatformStaff::firstWhere('email', 'first@bugalter.test');
        expect($admin->role)->toBe(PlatformRole::SuperAdmin)->and($admin->is_active)->toBeTrue()
            ->and(Hash::check('Very-Strong-Pass-2026', $admin->password))->toBeTrue()
            ->and($admin->hasTwoFactor())->toBeFalse();
    });

    it('refuses a duplicate email, and never takes the password as an argument', function () {
        PlatformStaff::factory()->create(['email' => 'dup@bugalter.test']);

        $this->artisan('platform:create-admin', ['email' => 'dup@bugalter.test', 'name' => 'Dup'])->assertFailed();
        expect(PlatformStaff::count())->toBe(1);

        $command = app(Kernel::class)->all()['platform:create-admin'];
        expect($command->getDefinition()->hasArgument('password'))->toBeFalse()->and($command->getDefinition()->hasOption('password'))->toBeFalse();
    });
});

describe('seeders never create default passwords outside local', function () {
    it('creates the dev admin only when APP_ENV=local', function () {
        (new PlatformAdminSeeder)->run();
        expect(PlatformStaff::count())->toBe(0);

        app()->detectEnvironment(fn () => 'local');
        (new PlatformAdminSeeder)->run();

        expect(PlatformStaff::pluck('email')->sort()->values()->all())->toBe(['admin@bugalter.local', 'support@bugalter.local']);
        expect(Hash::check(PlatformAdminSeeder::ADMIN_PASSWORD, PlatformStaff::firstWhere('email', 'admin@bugalter.local')->password))->toBeTrue();
    });

    it('creates nothing in production or staging, even through the main seeder', function (string $environment) {
        app()->detectEnvironment(fn () => $environment);

        // db:seed asks for confirmation in production; --force answers it, the seeder itself must still refuse.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        (new PlatformAdminSeeder)->run();

        expect(PlatformStaff::count())->toBe(0)->and(Tenant::count())->toBe(0);
    })->with(['production', 'staging', 'testing']);
});
