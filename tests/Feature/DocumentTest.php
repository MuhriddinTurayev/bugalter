<?php

use App\Actions\SaveDocument;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\DocumentSequence;
use App\Models\Transaction;

use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

function documentPayload(array $overrides = []): array
{
    return array_replace([
        'type' => 'invoice',
        'direction' => 'out',
        'contractor_id' => Contractor::factory()->create()->id,
        'issue_date' => '2026-03-15',
        'due_date' => '2026-03-25',
        'notes' => 'Test',
        'lines' => [
            ['name' => 'Konsalting', 'unit' => 'xizmat', 'quantity' => '2,5', 'unit_price' => '1 000,50', 'vat_percent' => '12'],
        ],
    ], $overrides);
}

it('creates a document and stores totals as integer minor units', function () {
    signIn(Role::ChiefAccountant);

    post(route('documents.store'), documentPayload())->assertRedirect();

    $document = Document::with('lines')->firstOrFail();

    expect($document->number)->toBe('INV-2026-0001')
        ->and($document->status)->toBe(DocumentStatus::Draft)
        ->and($document->subtotal_minor)->toBe(250_125)
        ->and($document->vat_minor)->toBe(30_015)
        ->and($document->total_minor)->toBe(280_140)
        ->and($document->lines)->toHaveCount(1)
        ->and($document->lines[0]->unit_price_minor)->toBe(100_050)
        ->and($document->lines[0]->vat_rate_bp)->toBe(1200)
        ->and($document->lines[0]->quantity)->toBe('2.500');
});

it('totals several lines with different VAT rates', function () {
    signIn();

    post(route('documents.store'), documentPayload(['lines' => [
        ['name' => 'A', 'quantity' => '1', 'unit_price' => '100', 'vat_percent' => '12'],
        ['name' => 'B', 'quantity' => '3', 'unit_price' => '50,50', 'vat_percent' => '0'],
        ['name' => 'C', 'quantity' => '2', 'unit_price' => '10', 'vat_percent' => ''],
    ]]))->assertRedirect();

    $document = Document::firstOrFail();

    expect($document->subtotal_minor)->toBe(10_000 + 15_150 + 2_000)
        ->and($document->vat_minor)->toBe(1_200)
        ->and($document->total_minor)->toBe(28_350);
});

it('validates the document form', function (array $override, string $errorKey) {
    signIn();

    post(route('documents.store'), documentPayload($override))->assertSessionHasErrors($errorKey);

    expect(Document::count())->toBe(0);
})->with([
    'no lines' => [['lines' => []], 'lines'],
    'zero quantity' => [['lines' => [['name' => 'A', 'quantity' => '0', 'unit_price' => '10']]], 'lines.0.quantity'],
    'bad quantity' => [['lines' => [['name' => 'A', 'quantity' => 'abc', 'unit_price' => '10']]], 'lines.0.quantity'],
    'bad price' => [['lines' => [['name' => 'A', 'quantity' => '1', 'unit_price' => '1,234']]], 'lines.0.unit_price'],
    'vat over 100' => [['lines' => [['name' => 'A', 'quantity' => '1', 'unit_price' => '1', 'vat_percent' => '120']]], 'lines.0.vat_percent'],
    'missing name' => [['lines' => [['name' => '', 'quantity' => '1', 'unit_price' => '1']]], 'lines.0.name'],
    'unknown contractor' => [['contractor_id' => 999], 'contractor_id'],
    'due before issue' => [['due_date' => '2026-03-01'], 'due_date'],
    'bad type' => [['type' => 'receipt'], 'type'],
]);

it('refuses documents for inactive contractors', function () {
    signIn();
    $inactive = Contractor::factory()->create(['is_active' => false]);

    post(route('documents.store'), documentPayload(['contractor_id' => $inactive->id]))->assertSessionHasErrors('contractor_id');
});

it('numbers documents sequentially per type and year', function () {
    $save = app(SaveDocument::class);
    $data = fn (string $type, string $date) => documentPayload(['type' => $type, 'issue_date' => $date, 'due_date' => null]) + [];

    $make = function (string $type, string $date) use ($save) {
        $payload = documentPayload(['type' => $type, 'issue_date' => $date, 'due_date' => null]);
        $payload['lines'] = [['name' => 'X', 'unit' => null, 'quantity' => '1', 'unit_price_minor' => 100, 'vat_rate_bp' => 0]];

        return $save($payload)->number;
    };

    expect($make('invoice', '2026-01-10'))->toBe('INV-2026-0001')
        ->and($make('invoice', '2026-02-10'))->toBe('INV-2026-0002')
        ->and($make('act', '2026-02-11'))->toBe('ACT-2026-0001')
        ->and($make('invoice', '2025-12-31'))->toBe('INV-2025-0001')
        ->and($make('invoice', '2026-03-01'))->toBe('INV-2026-0003');

    expect(DocumentSequence::where('type', 'invoice')->where('year', 2026)->value('last_number'))->toBe(3);
});

it('does not burn a number when saving fails', function () {
    $save = app(SaveDocument::class);

    try {
        $save([
            'type' => 'invoice', 'direction' => 'out', 'contractor_id' => 9999, // violates the foreign key
            'issue_date' => '2026-05-01', 'due_date' => null, 'notes' => null,
            'lines' => [['name' => 'X', 'unit' => null, 'quantity' => '1', 'unit_price_minor' => 100, 'vat_rate_bp' => 0]],
        ]);
    } catch (Throwable) {
        // expected
    }

    expect(DocumentSequence::where('year', 2026)->where('type', 'invoice')->value('last_number'))->toBeNull()
        ->and(Document::count())->toBe(0);
});

it('updates a draft and replaces its lines', function () {
    signIn();
    post(route('documents.store'), documentPayload())->assertRedirect();
    $document = Document::firstOrFail();

    put(route('documents.update', $document), documentPayload([
        'contractor_id' => $document->contractor_id,
        'lines' => [['name' => 'Yangi', 'quantity' => '1', 'unit_price' => '500', 'vat_percent' => '0']],
    ]))->assertRedirect(route('documents.show', $document));

    $document->refresh()->load('lines');

    expect($document->number)->toBe('INV-2026-0001')
        ->and($document->lines)->toHaveCount(1)
        ->and($document->total_minor)->toBe(50_000);
});

it('follows the status flow and refuses invalid transitions', function () {
    signIn(Role::Owner);
    $document = Document::factory()->create();

    patch(route('documents.status', $document), ['status' => 'paid'])->assertSessionHasErrors('status');
    expect($document->fresh()->status)->toBe(DocumentStatus::Draft);

    patch(route('documents.status', $document), ['status' => 'sent'])->assertSessionHasNoErrors();
    expect($document->fresh()->status)->toBe(DocumentStatus::Sent);

    patch(route('documents.status', $document), ['status' => 'paid'])->assertSessionHasNoErrors();
    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Paid)->and($document->paid_at)->not->toBeNull();

    patch(route('documents.status', $document), ['status' => 'cancelled'])->assertSessionHasErrors('status');
});

it('books the payment as a transaction when an account is chosen', function (string $direction, string $expectedType) {
    signIn(Role::Owner);
    $account = Account::factory()->create();
    $document = Document::factory()->status(DocumentStatus::Sent)->create([
        'direction' => $direction, 'total_minor' => 280_140,
    ]);

    patch(route('documents.status', $document), ['status' => 'paid', 'account_id' => $account->id])->assertSessionHasNoErrors();

    $transaction = Transaction::firstOrFail();

    expect($transaction->type->value)->toBe($expectedType)
        ->and($transaction->amount_minor)->toBe(280_140)
        ->and($transaction->document_id)->toBe($document->id)
        ->and($account->balanceMinor())->toBe($expectedType === 'income' ? 280_140 : -280_140);
})->with([['out', 'income'], ['in', 'expense']]);

it('does not create a transaction when no account is chosen', function () {
    signIn(Role::Owner);
    $document = Document::factory()->status(DocumentStatus::Sent)->create();

    patch(route('documents.status', $document), ['status' => 'paid'])->assertSessionHasNoErrors();

    expect(Transaction::count())->toBe(0);
});

it('filters the document list', function () {
    signIn();
    $a = Contractor::factory()->create(['name' => 'Alfa MChJ']);
    Document::factory()->create(['number' => 'INV-2026-0100', 'contractor_id' => $a->id, 'status' => DocumentStatus::Paid]);
    Document::factory()->create(['number' => 'ACT-2026-0200', 'type' => 'act', 'status' => DocumentStatus::Draft]);

    get('/documents?status=paid')->assertSee('INV-2026-0100')->assertDontSee('ACT-2026-0200');
    get('/documents?type=act')->assertSee('ACT-2026-0200')->assertDontSee('INV-2026-0100');
    get('/documents?q=0100')->assertSee('INV-2026-0100')->assertDontSee('ACT-2026-0200');
    get('/documents?contractor_id='.$a->id)->assertSee('INV-2026-0100')->assertDontSee('ACT-2026-0200');
});

it('deletes drafts only through the chief accountant or owner', function () {
    signIn(Role::ChiefAccountant);
    $draft = Document::factory()->create();
    $sent = Document::factory()->status(DocumentStatus::Sent)->create();

    $this->delete(route('documents.destroy', $draft))->assertRedirect(route('documents.index'));
    $this->delete(route('documents.destroy', $sent))->assertForbidden();

    expect(Document::count())->toBe(1);
});
