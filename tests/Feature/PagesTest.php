<?php

use App\Enums\Role;
use App\Models\Account;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Transaction;
use Database\Seeders\TaxRuleSeeder;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

beforeEach(function () {
    CompanySetting::create(['name' => 'Test Kompaniya MChJ', 'stir' => '300000001']);
    Account::factory()->cash()->create(['name' => 'Kassa']);
    Account::factory()->create(['name' => 'Bank hisobi']);
    Category::factory()->create(['name' => 'Savdo']);
    $this->contractor = Contractor::factory()->create(['name' => 'Namuna Hamkor MChJ']);
    $this->document = Document::factory()->create(['contractor_id' => $this->contractor->id, 'number' => 'INV-2026-0001']);
    Employee::create(['full_name' => 'Karimov Test', 'pinfl' => '31010199000123', 'salary_minor' => 500_000_00]);
});

it('renders the dashboard with every card endpoint wired', function () {
    signIn(Role::Owner);

    get('/')->assertOk()
        ->assertSee('Xush kelibsiz')
        ->assertSee(__('ui.dashboard.balance.title'))
        ->assertSee(__('ui.dashboard.trend.title'))
        ->assertSee(__('ui.dashboard.cashflow.title'))
        ->assertSee(__('ui.dashboard.payments.title'))
        ->assertSee(__('ui.dashboard.receivables.title'))
        ->assertSee(__('ui.dashboard.tax.title'))
        ->assertSee(__('ui.dashboard.ai.title'))
        ->assertSee(route('ai.index'))
        ->assertSee(__('ui.dashboard.ai.not_configured'))
        ->assertSee('dashboard\/cards\/balance', false)
        ->assertSee('dashboard\/cards\/receivables', false)
        ->assertSee(__('ui.dashboard.new_document'));
});

it('serves every dashboard card as json', function (string $card) {
    signIn(Role::Cashier);

    get(route('dashboard.card', $card))->assertOk()->assertJsonIsObject();
})->with(['balance', 'weekly', 'trend', 'cashflow', 'payments', 'receivables']);

it('rejects unknown cards and invalid ranges', function () {
    signIn();

    getJson('/dashboard/cards/secrets')->assertNotFound();
    getJson(route('dashboard.card', ['card' => 'trend', 'from' => '2026-05-01', 'to' => '2026-01-01']))->assertUnprocessable();
    getJson(route('dashboard.card', ['card' => 'trend', 'mode' => 'nope']))->assertUnprocessable();
});

it('renders the main pages for every role', function (Role $role, string $uri, string $text) {
    signIn($role);

    get($uri)->assertOk()->assertSee($text, false);
})->with(function () {
    $pages = [
        ['/contractors', 'Namuna Hamkor MChJ'],
        ['/contractors/create', 'contractors'],
        ['/documents', 'INV-2026-0001'],
        ['/documents/create', 'documentForm'],
        ['/bank', 'Bank hisobi'],
        ['/bank/transactions/create', 'occurred_on'],
        ['/bank/categories', 'Savdo'],
        ['/history', 'history'],
        ['/search?q=Namuna', 'Namuna Hamkor MChJ'],
    ];

    foreach ([Role::Owner, Role::ChiefAccountant, Role::Cashier] as $role) {
        foreach ($pages as [$uri, $text]) {
            yield "{$role->value} $uri" => [$role, $uri, $text];
        }
    }
});

it('renders detail and edit pages', function () {
    signIn(Role::ChiefAccountant);

    get(route('contractors.edit', $this->contractor))->assertOk()->assertSee('Namuna Hamkor MChJ');
    get(route('documents.show', $this->document))->assertOk()->assertSee('INV-2026-0001');
    get(route('documents.edit', $this->document))->assertOk()->assertSee('INV-2026-0001');
});

it('renders the management pages for roles that may use them', function () {
    signIn(Role::Owner);

    get('/reports')->assertOk()->assertSee(__('ui.reports.title'));
    get('/settings')->assertOk()->assertSee('Test Kompaniya MChJ');
    get('/settings/users')->assertOk()->assertSee(__('ui.settings.users'));
    get('/settings/employees')->assertOk()->assertSee('Karimov Test')->assertSee('0123')->assertDontSee('31010199000123');
    get('/bank/accounts/create')->assertOk();
    get('/bank/import')->assertOk()->assertSee(__('ui.bank.import_step1'));
});

it('shows a clear state instead of tax figures while tax rules are not loaded', function () {
    signIn(Role::ChiefAccountant);

    get('/reports')->assertOk()->assertSee(__('ui.reports.tax_missing_title'));

    app(TaxRuleSeeder::class)->sync();

    get('/reports')->assertOk()->assertSee(__('ui.reports.tax_ready_title'))->assertSee(route('tax.index'));
});

it('summarises income and expense on the reports page', function () {
    signIn(Role::Owner);
    $account = Account::first();
    $sales = Category::factory()->create(['name' => 'Sotuv', 'type' => 'income']);

    Transaction::create([
        'account_id' => $account->id, 'category_id' => $sales->id, 'type' => 'income', 'amount_minor' => 1_000_000_00,
        'occurred_at' => now(), 'status' => 'completed',
    ]);
    Transaction::create([
        'account_id' => $account->id, 'type' => 'expense', 'amount_minor' => 250_000_00,
        'occurred_at' => now(), 'status' => 'completed',
    ]);

    get('/reports')->assertOk()->assertSee("1\u{00A0}000\u{00A0}000\u{00A0}soʻm", false)->assertSee("750\u{00A0}000\u{00A0}soʻm", false);
});

it('renders the PDF of a document with a font that has Uzbek and Cyrillic glyphs', function () {
    signIn(Role::Owner);
    $this->document->lines()->create([
        'position' => 1, 'name' => 'Oʻrnatish xizmati / Установка', 'quantity' => '1.000',
        'unit_price_minor' => 100_000_00, 'vat_rate_bp' => 0, 'subtotal_minor' => 100_000_00, 'vat_minor' => 0, 'total_minor' => 100_000_00,
    ]);

    $response = get(route('documents.pdf', $this->document))->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('INV-2026-0001.pdf')
        ->and($response->getContent())->toStartWith('%PDF');
});

it('writes relative dates in Latin Uzbek, not Carbon\'s Cyrillic "uz"', function () {
    app()->setLocale('uz');
    expect(now()->subMinutes(4)->diffForHumans())->toBe('4 daqiqa avval');

    app()->setLocale('ru');
    expect(now()->subMinutes(4)->diffForHumans())->toBe('4 минуты назад');
});
