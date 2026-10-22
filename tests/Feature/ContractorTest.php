<?php

use App\Enums\Role;
use App\Models\Contractor;
use Illuminate\Http\UploadedFile;

use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

it('creates a contractor', function () {
    signIn(Role::ChiefAccountant);

    post(route('contractors.store'), [
        'name' => 'Oltin Vodiy MChJ', 'stir' => '301234567', 'type' => 'supplier', 'phone' => '+998901112233',
        'email' => 'info@oltin.test', 'bank_account' => '20208000900000000001', 'mfo' => '00001',
    ])->assertRedirect(route('contractors.index'));

    $contractor = Contractor::firstOrFail();

    expect($contractor->name)->toBe('Oltin Vodiy MChJ')
        ->and($contractor->type->value)->toBe('supplier')
        ->and($contractor->is_active)->toBeTrue();
});

it('validates contractor fields', function (array $override, string $field) {
    signIn();

    post(route('contractors.store'), $override + ['name' => 'X', 'type' => 'customer'])->assertSessionHasErrors($field);
})->with([
    'stir too short' => [['stir' => '12345'], 'stir'],
    'stir letters' => [['stir' => '30123456A'], 'stir'],
    'account length' => [['bank_account' => '123'], 'bank_account'],
    'mfo length' => [['mfo' => '1234'], 'mfo'],
    'email' => [['email' => 'nope'], 'email'],
    'type' => [['type' => 'alien'], 'type'],
    'name' => [['name' => ''], 'name'],
]);

it('accepts a 14 digit STIR for individuals', function () {
    signIn();

    post(route('contractors.store'), ['name' => 'Yakka tartib', 'type' => 'customer', 'stir' => '31234567890123'])->assertSessionHasNoErrors();
});

it('updates a contractor', function () {
    signIn();
    $contractor = Contractor::factory()->create(['name' => 'Eski nom']);

    put(route('contractors.update', $contractor), ['name' => 'Yangi nom', 'type' => 'both'])->assertRedirect(route('contractors.index'));

    expect($contractor->fresh()->name)->toBe('Yangi nom')->and($contractor->fresh()->type->value)->toBe('both');
});

it('deactivates and reactivates a contractor', function () {
    signIn(Role::ChiefAccountant);
    $contractor = Contractor::factory()->create();

    patch(route('contractors.toggle', $contractor))->assertRedirect();
    expect($contractor->fresh()->is_active)->toBeFalse();

    patch(route('contractors.toggle', $contractor))->assertRedirect();
    expect($contractor->fresh()->is_active)->toBeTrue();
});

it('searches and filters the list', function () {
    signIn();
    Contractor::factory()->create(['name' => 'Alfa Savdo', 'stir' => '301111111', 'type' => 'customer']);
    Contractor::factory()->create(['name' => 'Beta Servis', 'stir' => '302222222', 'type' => 'supplier']);
    Contractor::factory()->create(['name' => 'Gamma Arxiv', 'is_active' => false]);

    get('/contractors?q=Alfa')->assertSee('Alfa Savdo')->assertDontSee('Beta Servis');
    get('/contractors?q=302222')->assertSee('Beta Servis')->assertDontSee('Alfa Savdo');
    get('/contractors?type=supplier')->assertSee('Beta Servis')->assertDontSee('Alfa Savdo');
    get('/contractors')->assertDontSee('Gamma Arxiv');
    get('/contractors?status=inactive')->assertSee('Gamma Arxiv')->assertDontSee('Alfa Savdo');
    get('/contractors?status=all')->assertSee('Gamma Arxiv')->assertSee('Alfa Savdo');
});

it('treats search wildcards literally', function () {
    signIn();
    Contractor::factory()->create(['name' => 'Alfa Savdo']);

    get('/contractors?q=%25')->assertDontSee('Alfa Savdo');
});

it('exports contractors as xlsx and csv', function () {
    signIn();
    Contractor::factory()->create(['name' => 'Eksport MChJ']);

    $xlsx = get(route('contractors.export'))->assertOk();
    expect($xlsx->headers->get('content-disposition'))->toContain('.xlsx');

    $csv = get(route('contractors.export', ['format' => 'csv']))->assertOk();
    expect($csv->headers->get('content-disposition'))->toContain('.csv')
        ->and($csv->baseResponse->getFile()->getContent())->toContain('Eksport MChJ');
});

it('imports contractors from a csv file, updating by STIR', function () {
    signIn(Role::ChiefAccountant);
    Contractor::factory()->create(['name' => 'Eski nom', 'stir' => '301234567']);

    $csv = implode("\n", [
        'name,stir,type,phone',
        'Yangi MChJ,309999999,supplier,+998901234567',
        'Yangilangan nom,301234567,customer,',
        ',,,',
        'Xato MChJ,123,customer,',
    ]);

    post(route('contractors.import'), ['file' => UploadedFile::fake()->createWithContent('contractors.csv', $csv)])
        ->assertRedirect(route('contractors.index'))
        ->assertSessionHas('status')
        ->assertSessionHas('import_errors');

    expect(Contractor::count())->toBe(2)
        ->and(Contractor::where('stir', '309999999')->first()->type->value)->toBe('supplier')
        ->and(Contractor::where('stir', '301234567')->first()->name)->toBe('Yangilangan nom');
});

it('imports a file that uses the localized column headings of the export', function () {
    signIn(Role::ChiefAccountant);

    $csv = "Nomi,STIR,Turi\nOʻzbek Import MChJ,305555555,customer\n";

    post(route('contractors.import'), ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv)])->assertRedirect();

    expect(Contractor::where('name', 'Oʻzbek Import MChJ')->exists())->toBeTrue();
});

it('rejects import files of the wrong type', function () {
    signIn(Role::ChiefAccountant);

    post(route('contractors.import'), ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
});
