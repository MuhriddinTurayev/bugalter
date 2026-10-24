<?php

use App\Actions\ImportTransactions;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Category;
use App\Models\Contractor;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

it('adds an income and an expense and keeps the balance in minor units', function () {
    signIn(Role::Cashier);
    $account = Account::factory()->cash()->create(['opening_balance_minor' => 1_000_000_00]);
    $income = Category::factory()->create(['type' => 'income']);
    $expense = Category::factory()->expense()->create();

    post(route('bank.transactions.store'), [
        'type' => 'income', 'account_id' => $account->id, 'category_id' => $income->id,
        'amount' => '2 500 000,50', 'occurred_on' => now()->toDateString(), 'description' => 'Savdo',
    ])->assertRedirect(route('bank.index'));

    post(route('bank.transactions.store'), [
        'type' => 'expense', 'account_id' => $account->id, 'category_id' => $expense->id,
        'amount' => '300000', 'occurred_on' => now()->toDateString(),
    ])->assertRedirect(route('bank.index'));

    expect(Transaction::count())->toBe(2)
        ->and(Transaction::where('type', 'income')->value('amount_minor'))->toBe(250_000_050)
        ->and($account->balanceMinor())->toBe(100_000_000 + 250_000_050 - 30_000_000);
});

it('ignores pending transactions in the balance', function () {
    $account = Account::factory()->create(['opening_balance_minor' => 100]);
    Transaction::create(['account_id' => $account->id, 'type' => 'income', 'amount_minor' => 500, 'occurred_at' => now(), 'status' => 'pending']);

    expect($account->balanceMinor())->toBe(100);
});

it('validates transactions', function (array $override, string $field) {
    signIn();
    $account = Account::factory()->create();

    post(route('bank.transactions.store'), $override + [
        'type' => 'income', 'account_id' => $account->id, 'amount' => '100', 'occurred_on' => now()->toDateString(),
    ])->assertSessionHasErrors($field);

    expect(Transaction::count())->toBe(0);
})->with([
    'zero amount' => [['amount' => '0'], 'amount'],
    'bad amount' => [['amount' => '12,345'], 'amount'],
    'future date' => [['occurred_on' => '2999-01-01'], 'occurred_on'],
    'missing account' => [['account_id' => 999], 'account_id'],
    'bad type' => [['type' => 'transfer'], 'type'],
]);

it('rejects a category of the wrong type', function () {
    signIn();
    $account = Account::factory()->create();
    $expense = Category::factory()->expense()->create();

    post(route('bank.transactions.store'), [
        'type' => 'income', 'account_id' => $account->id, 'category_id' => $expense->id,
        'amount' => '100', 'occurred_on' => now()->toDateString(),
    ])->assertSessionHasErrors('category_id');
});

it('creates and edits accounts, restricted to the chief accountant and owner', function () {
    signIn(Role::ChiefAccountant);

    post(route('bank.accounts.store'), [
        'name' => 'Yangi hisob', 'type' => 'bank', 'bank_name' => 'Demo Bank', 'account_number' => '20208000900000000009', 'opening_balance' => '1000',
    ])->assertRedirect(route('bank.index'));

    $account = Account::firstOrFail();
    expect($account->opening_balance_minor)->toBe(100_000);

    $this->put(route('bank.accounts.update', $account), ['name' => 'Kassa-2', 'type' => 'cash'])->assertRedirect();
    expect($account->fresh()->name)->toBe('Kassa-2')->and($account->fresh()->account_number)->toBeNull();
});

it('manages categories and deactivates instead of deleting', function () {
    signIn(Role::ChiefAccountant);

    post(route('bank.categories.store'), ['name' => 'Reklama', 'type' => 'expense'])->assertSessionHasNoErrors();
    $category = Category::firstOrFail();

    patch(route('bank.categories.toggle', $category))->assertRedirect();
    expect($category->fresh()->is_active)->toBeFalse();
    get('/bank/categories')->assertOk()->assertSee('Reklama');
});

it('filters transactions', function () {
    signIn();
    $a = Account::factory()->create(['name' => 'Birinchi']);
    $b = Account::factory()->create(['name' => 'Ikkinchi']);
    $contractor = Contractor::factory()->create(['name' => 'Gulliver MChJ']);
    Transaction::create(['account_id' => $a->id, 'type' => 'income', 'amount_minor' => 100, 'occurred_at' => '2026-01-10 10:00', 'description' => 'Birinchi yozuv', 'contractor_id' => $contractor->id]);
    Transaction::create(['account_id' => $b->id, 'type' => 'expense', 'amount_minor' => 200, 'occurred_at' => '2026-02-10 10:00', 'description' => 'Ikkinchi yozuv']);

    get('/bank?type=expense')->assertSee('Ikkinchi yozuv')->assertDontSee('Birinchi yozuv');
    get('/bank?account_id='.$a->id)->assertSee('Birinchi yozuv')->assertDontSee('Ikkinchi yozuv');
    get('/bank?q=Gulliver')->assertSee('Gulliver MChJ')->assertDontSee('Ikkinchi yozuv');
    get('/history?from=2026-02-01')->assertSee('Ikkinchi yozuv')->assertDontSee('Birinchi yozuv');
});

describe('CSV import with column mapping', function () {
    beforeEach(fn () => Storage::fake('local'));

    it('maps columns, handles signs and reports skipped rows', function () {
        signIn(Role::ChiefAccountant);
        $account = Account::factory()->create();

        $csv = implode("\n", [
            'Sana;Summa;Izoh;Tur',
            '15.03.2026;1 500 000,50;Mijozdan tushum;',
            '16.03.2026;-250 000;Ijara;',
            '17.03.2026;100;Qo\'lda;chiqim',
            'bad-date;100;Xato;',
            '18.03.2026;abc;Xato summa;',
        ]);

        post(route('bank.import.upload'), ['file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)])
            ->assertRedirect(route('bank.import'));

        get('/bank/import')->assertOk()->assertSee('Mijozdan tushum')->assertSee(__('ui.bank.import_step2'));

        post(route('bank.import.store'), [
            'account_id' => $account->id, 'date_format' => 'd.m.Y',
            'col_date' => 0, 'col_amount' => 1, 'col_description' => 2, 'col_type' => 3,
        ])->assertRedirect(route('bank.index'))->assertSessionHas('import_errors');

        $rows = Transaction::orderBy('occurred_at')->get();

        expect($rows)->toHaveCount(3)
            ->and($rows[0]->type->value)->toBe('income')->and($rows[0]->amount_minor)->toBe(150_000_050)
            ->and($rows[1]->type->value)->toBe('expense')->and($rows[1]->amount_minor)->toBe(25_000_000)
            ->and($rows[2]->type->value)->toBe('expense')
            ->and($rows[0]->occurred_at->format('Y-m-d'))->toBe('2026-03-15');

        expect(session('transaction_import'))->toBeNull();
    });

    it('pre-selects columns from uz, ru and en header names', function (array $headers, array $expected) {
        expect(app(ImportTransactions::class)->guessColumns($headers))->toBe($expected);
    })->with([
        'uzbek' => [['Hujjat raqami', 'Sana', 'Toʻlov maqsadi', 'Summa (soʻm)', 'Turi'], ['date' => 1, 'amount' => 3, 'description' => 2, 'type' => 4, 'reference' => 0]],
        'russian' => [['Дата', 'Назначение платежа', 'Сумма'], ['date' => 0, 'amount' => 2, 'description' => 1, 'type' => null, 'reference' => null]],
        'unknown' => [['A', 'B', 'C'], ['date' => 0, 'amount' => 1, 'description' => null, 'type' => null, 'reference' => null]],
    ]);

    it('selects the guessed columns on the mapping step', function () {
        signIn(Role::ChiefAccountant);
        post(route('bank.import.upload'), ['file' => UploadedFile::fake()->createWithContent('s.csv', "Summa;Sana;Izoh\n100;15.03.2026;Test\n")]);

        get('/bank/import')->assertOk()
            ->assertSee('<option value="2" selected>Izoh</option>', false)
            ->assertSee('<option value="1" selected>Sana</option>', false);
    });

    it('detects comma and tab delimiters and a BOM', function () {
        signIn(Role::Owner);
        $account = Account::factory()->create();

        $csv = "\xEF\xBB\xBFDate,Amount\n2026-04-01,500\n";

        post(route('bank.import.upload'), ['file' => UploadedFile::fake()->createWithContent('s.csv', $csv)]);
        post(route('bank.import.store'), ['account_id' => $account->id, 'date_format' => 'Y-m-d', 'col_date' => 0, 'col_amount' => 1])->assertRedirect(route('bank.index'));

        expect(Transaction::count())->toBe(1)->and(Transaction::first()->amount_minor)->toBe(50_000);
    });

    it('requires the date and amount columns to be different', function () {
        signIn(Role::Owner);
        $account = Account::factory()->create();

        post(route('bank.import.upload'), ['file' => UploadedFile::fake()->createWithContent('s.csv', "a,b\n1,2\n")]);

        post(route('bank.import.store'), ['account_id' => $account->id, 'date_format' => 'Y-m-d', 'col_date' => 0, 'col_amount' => 0])
            ->assertSessionHasErrors('col_amount');
    });

    it('can be cancelled and removes the uploaded file', function () {
        signIn(Role::Owner);

        post(route('bank.import.upload'), ['file' => UploadedFile::fake()->createWithContent('s.csv', "a,b\n1,2\n")]);
        expect(Storage::disk('local')->allFiles('imports'))->toHaveCount(1);

        post(route('bank.import.cancel'))->assertRedirect(route('bank.index'));

        expect(Storage::disk('local')->allFiles('imports'))->toBeEmpty();
    });

    it('rejects non-csv uploads', function () {
        signIn(Role::Owner);

        post(route('bank.import.upload'), ['file' => UploadedFile::fake()->create('statement.pdf', 10)])->assertSessionHasErrors('file');
    });
});
