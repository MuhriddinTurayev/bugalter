<?php

use App\Enums\Role;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\Transaction;
use App\Notifications\AppNotification;
use App\Services\CurrentCompany;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

it('updates the company profile', function () {
    signIn(Role::ChiefAccountant);
    CompanySetting::create(['name' => 'Eski MChJ']);

    put(route('settings.company.update'), [
        'name' => 'Yangi MChJ', 'stir' => '300000001', 'bank_account' => '20208000900000000001', 'mfo' => '00001',
        'vat_payer' => '1', 'default_vat_percent' => '12,5',
    ])->assertRedirect(route('settings.company'));

    $company = CompanySetting::firstOrFail();

    expect($company->name)->toBe('Yangi MChJ')
        ->and($company->vat_payer)->toBeTrue()
        ->and($company->default_vat_rate_bp)->toBe(1250);
});

it('validates the company profile', function () {
    signIn(Role::Owner);

    put(route('settings.company.update'), ['name' => '', 'stir' => '12'])->assertSessionHasErrors(['name', 'stir']);
});

it('reads the company only through the CurrentCompany service', function () {
    $service = app(CurrentCompany::class);

    expect($service->isSingleCompany())->toBeTrue()
        ->and($service->id())->toBeNull()
        ->and($service->settings()->exists)->toBeFalse();

    CompanySetting::create(['name' => 'Namuna MChJ']);
    $service->forget();

    expect($service->name())->toBe('Namuna MChJ');
});

it('shows and clears notifications for the signed-in user', function () {
    $user = signIn(Role::Owner);
    $user->notify(new AppNotification('ui.notifications.demo_welcome'));

    get('/')->assertSee(__('ui.notifications.demo_welcome'));
    expect($user->unreadNotifications()->count())->toBe(1);

    post(route('notifications.read'))->assertRedirect();

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('computes the balance card from accounts', function () {
    signIn();
    $cash = Account::factory()->cash()->create(['name' => 'Kassa', 'opening_balance_minor' => 1_000_00]);
    $bank = Account::factory()->create(['name' => 'Bank', 'opening_balance_minor' => 5_000_00]);
    Transaction::create(['account_id' => $bank->id, 'type' => 'income', 'amount_minor' => 2_500_00, 'occurred_at' => now(), 'status' => 'completed']);
    Transaction::create(['account_id' => $cash->id, 'type' => 'expense', 'amount_minor' => 400_00, 'occurred_at' => now(), 'status' => 'completed']);

    get(route('dashboard.card', 'balance'))->assertOk()
        ->assertJsonPath('total_minor', 8_100_00)
        ->assertJsonCount(2, 'accounts');
});

it('aggregates income and expense per month for the trend card', function () {
    signIn();
    $account = Account::factory()->create();
    $from = now()->subMonths(1)->startOfMonth();

    Transaction::create(['account_id' => $account->id, 'type' => 'income', 'amount_minor' => 5_000_000_00, 'occurred_at' => $from->copy()->addDays(3), 'status' => 'completed']);
    Transaction::create(['account_id' => $account->id, 'type' => 'expense', 'amount_minor' => 1_000_000_00, 'occurred_at' => now(), 'status' => 'completed']);

    $json = get(route('dashboard.card', ['card' => 'trend', 'from' => $from->toDateString(), 'to' => now()->toDateString()]))->assertOk()->json();

    expect($json['income'])->toBe([5_000_000, 0])
        ->and($json['expense'])->toBe([0, 1_000_000])
        ->and($json['has_data'])->toBeTrue()
        ->and($json['labels'])->toHaveCount(2);
});

it('returns the last twelve months for the annual trend', function () {
    signIn();

    $json = get(route('dashboard.card', ['card' => 'trend', 'mode' => 'annual']))->assertOk()->json();

    expect($json['labels'])->toHaveCount(12)->and($json['has_data'])->toBeFalse();
});

it('reports an empty cash flow instead of failing without data', function () {
    signIn();

    get(route('dashboard.card', 'cashflow'))->assertOk()->assertJsonPath('has_data', false);
    get(route('dashboard.card', 'payments'))->assertOk()->assertJsonPath('has_data', false);
    get(route('dashboard.card', 'receivables'))->assertOk()->assertJsonPath('has_data', false);
});

it('lists overdue receivables with the people behind them', function () {
    signIn();
    $contractor = Contractor::factory()->create(['name' => 'Qarzdor MChJ']);
    Document::factory()->create([
        'contractor_id' => $contractor->id, 'status' => 'sent', 'direction' => 'out',
        'issue_date' => now()->subDays(30), 'due_date' => now()->subDays(20), 'total_minor' => 9_000_000_00,
    ]);
    Document::factory()->create(['status' => 'sent', 'direction' => 'in', 'total_minor' => 1_000_00]);

    $json = get(route('dashboard.card', 'receivables'))->assertOk()->json();

    expect($json['receivable_count'])->toBe(1)
        ->and($json['payable_count'])->toBe(1)
        ->and($json['overdue_count'])->toBe(1)
        ->and($json['overdue_people'][0]['name'])->toBe('Qarzdor MChJ');
});

it('compares revenue with the previous week', function () {
    signIn();
    $account = Account::factory()->create();
    Transaction::create(['account_id' => $account->id, 'type' => 'income', 'amount_minor' => 200_00, 'occurred_at' => now()->subDays(2), 'status' => 'completed']);
    Transaction::create(['account_id' => $account->id, 'type' => 'income', 'amount_minor' => 100_00, 'occurred_at' => now()->subDays(10), 'status' => 'completed']);

    get(route('dashboard.card', 'weekly'))->assertOk()
        ->assertJsonPath('amount_minor', 200_00)
        ->assertJsonPath('change_percent', 100);
});

it('is reachable through the single protected route group only', function () {
    $unprotected = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => ! in_array('auth', $route->gatherMiddleware(), true) && ! in_array('guest', $route->gatherMiddleware(), true))
        // Platform routes are protected by their own guard and 2FA middleware (see PlatformSecurityTest).
        ->reject(fn ($route) => str_starts_with((string) $route->getName(), 'platform.'))
        ->map(fn ($route) => $route->uri())
        ->reject(fn (string $uri) => in_array($uri, ['up', 'locale/{locale}', 'storage/{path}'], true))
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});
