<?php

use App\Actions\SaveDocument;
use App\Enums\DocumentStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\DocumentSequence;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\UserInvitation;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

describe('people', function () {
    it('lets the owner invite a chief accountant who then sets a password', function () {
        Notification::fake();
        signIn(Role::Owner);

        post(route('settings.users.store'), ['name' => 'Dilnoza Karimova', 'email' => 'Dilnoza@Test.uz', 'phone' => '+998901234567', 'role' => 'chief_accountant'])
            ->assertRedirect()
            ->assertSessionHas('invitation_url');

        $user = User::firstWhere('email', 'dilnoza@test.uz');
        expect($user->role)->toBe(Role::ChiefAccountant)->and($user->isInvitationPending())->toBeTrue();
        Notification::assertSentTo($user, UserInvitation::class);
        expect(AuditLog::query()->where('action', 'user.invited')->exists())->toBeTrue();

        $url = session('invitation_url');
        auth()->logout();
        post(parse_url($url, PHP_URL_PATH), ['password' => 'Strong-Pass-2026', 'password_confirmation' => 'Strong-Pass-2026'])->assertRedirect(route('dashboard'));

        expect($user->fresh()->isInvitationPending())->toBeFalse();
    });

    it('changes roles, access and extra permissions, but never the owner', function () {
        $owner = signIn(Role::Owner);
        $cashier = User::factory()->role(Role::Cashier)->create();

        patch(route('settings.users.update', $cashier), ['role' => 'chief_accountant'])->assertRedirect();
        expect($cashier->fresh()->role)->toBe(Role::ChiefAccountant);

        put(route('settings.users.permissions', $cashier), ['permissions' => ['reports.approve']])->assertRedirect();
        expect($cashier->fresh()->load('grantedPermissions')->hasPermission(Permission::ReportsApprove))->toBeTrue();

        patch(route('settings.users.toggle', $cashier))->assertRedirect();
        expect($cashier->fresh()->is_active)->toBeFalse()
            ->and($cashier->fresh()->hasPermission(Permission::ReportsApprove))->toBeFalse(); // inactive people hold nothing

        patch(route('settings.users.update', $owner), ['role' => 'cashier'])->assertForbidden();
        patch(route('settings.users.toggle', $owner))->assertForbidden();
    });

    it('keeps people management to the owner', function () {
        signIn(Role::ChiefAccountant);

        get(route('settings.users'))->assertForbidden();
        post(route('settings.users.store'), ['name' => 'X', 'email' => 'x@x.uz', 'role' => 'cashier'])->assertForbidden();
    });

    it('manages employees with salaries in soʻm', function () {
        signIn(Role::ChiefAccountant);

        post(route('settings.employees.store'), ['full_name' => 'Ali Valiyev', 'pinfl' => '123', 'status' => 'active', 'salary' => '5 000 000'])->assertSessionHasErrors('pinfl');
        post(route('settings.employees.store'), ['full_name' => 'Ali Valiyev', 'pinfl' => '31234567890123', 'status' => 'active', 'salary' => '5 000 000,50'])->assertRedirect(route('settings.employees'));

        $employee = Employee::first();
        expect($employee->salary_minor)->toBe(500_000_050);

        put(route('settings.employees.update', $employee), ['full_name' => 'Ali Valiyev', 'status' => 'on_leave', 'salary' => '6000000'])->assertRedirect();
        expect($employee->fresh()->status)->toBe('on_leave')->and($employee->fresh()->salary_minor)->toBe(600_000_000);

        $this->delete(route('settings.employees.destroy', $employee))->assertRedirect();
        expect(Employee::count())->toBe(0)->and(Employee::withTrashed()->count())->toBe(1);

        signIn(Role::Cashier);
        get(route('settings.employees'))->assertForbidden();
    });
});

describe('fixes', function () {
    it('books a payment only once even when "paid" is sent twice', function () {
        signIn(Role::Owner);
        $account = Account::factory()->create();
        $document = Document::factory()->status(DocumentStatus::Sent)->create(['total_minor' => 1_000_000]);

        patch(route('documents.status', $document), ['status' => 'paid', 'account_id' => $account->id])->assertRedirect();
        patch(route('documents.status', $document), ['status' => 'paid', 'account_id' => $account->id])->assertSessionHasErrors('status');

        expect(Transaction::where('document_id', $document->id)->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'document.status_changed')->count())->toBe(1);
    });

    it('never leaves a gap in the numbering when a draft is removed', function () {
        signIn(Role::Owner);
        $contractor = Contractor::factory()->create();
        $make = fn () => app(SaveDocument::class)([
            'type' => 'invoice', 'direction' => 'out', 'contractor_id' => $contractor->id, 'issue_date' => '2026-03-01', 'due_date' => null, 'notes' => null,
            'lines' => [['name' => 'X', 'unit' => null, 'quantity' => '1', 'unit_price_minor' => 100, 'vat_rate_bp' => 0]],
        ]);

        $first = $make();
        $second = $make();

        // An older number: cancelled, so the number stays used.
        $this->delete(route('documents.destroy', $first))->assertRedirect();
        expect($first->fresh()->status)->toBe(DocumentStatus::Cancelled);

        // The latest number: really deleted and the counter steps back.
        $this->delete(route('documents.destroy', $second))->assertRedirect();
        expect(Document::find($second->id))->toBeNull()
            ->and(DocumentSequence::first()->last_number)->toBe(1)
            ->and($make()->number)->toBe('INV-2026-0002');
    });

    it('writes business changes to the company audit log with the person who made them', function () {
        $owner = signIn(Role::Owner);

        post(route('contractors.store'), ['name' => 'Audit MChJ', 'type' => 'customer'])->assertRedirect();

        $log = AuditLog::query()->where('action', 'contractor.created')->first();
        expect($log->actor_type)->toBe('user')->and($log->actor_name)->toBe($owner->name);

        get(route('settings.audit'))->assertOk()->assertSee(__('ui.audit.actions.contractor.created'));
        get(route('settings.audit', ['actor' => 'ai']))->assertOk()->assertDontSee(__('ui.audit.actions.contractor.created'));
    });
});

describe('plan limits and subscription', function () {
    beforeEach(function () {
        useSqliteTenancy();
        config(['app.single_company' => false]);

        $plan = Plan::factory()->create(['limits' => ['max_users' => 1, 'max_documents_per_month' => 0]]);
        app(TenantProvisioningService::class)->provision([
            'name' => 'Acme MChJ', 'stir' => '311111111', 'slug' => 'acme', 'plan_id' => $plan->id,
            'owner_name' => 'Owner', 'owner_email' => 'owner@acme.test', 'owner_phone' => null,
        ]);

        $this->owner = Tenant::find('acme')->run(function () {
            $owner = User::query()->firstOrFail();
            $owner->forceFill(['password' => 'Strong-Pass-2026', 'invitation_token_hash' => null])->save();

            return $owner;
        });

        resetRequestContext();
        $this->actingAs($this->owner);
    });

    afterEach(fn () => cleanUpTenants());

    it('refuses a new user over the plan limit', function () {
        post(company('acme', '/settings/users'), ['name' => 'Second', 'email' => 'second@acme.test', 'role' => 'cashier'])
            ->assertSessionHasErrors('limit');

        expect(Tenant::find('acme')->run(fn () => User::count()))->toBe(1);
    });

    it('makes the company read-only once the subscription ends', function () {
        Tenant::find('acme')->update(['subscription_ends_at' => now()->subDay()]);

        get(company('acme', '/contractors'))->assertOk()->assertSee(__('ui.limits.subscription_banner'));
        post(company('acme', '/contractors'), ['name' => 'Blocked MChJ', 'type' => 'customer'])->assertSessionHas('error', __('ui.limits.subscription_expired'));

        expect(Tenant::find('acme')->run(fn () => Contractor::count()))->toBe(0);
    });
});
