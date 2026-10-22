<?php

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Contractor;
use App\Models\Document;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

it('keeps tax, reports and settings areas away from the cashier', function (string $uri) {
    signIn(Role::Cashier);

    get($uri)->assertForbidden();
})->with(['/reports', '/settings', '/settings/users', '/settings/employees', '/bank/accounts/create', '/bank/import']);

it('does not show restricted menu entries to the cashier', function () {
    signIn(Role::Cashier);

    get('/')->assertOk()
        ->assertDontSee(route('reports'))
        ->assertDontSee(route('settings.company'))
        ->assertSee(route('documents.index'))
        ->assertSee(route('contractors.index'));
});

it('shows reports and settings entries to the chief accountant and owner', function (Role $role) {
    signIn($role);

    get('/')->assertSee(route('reports'))->assertSee(route('settings.company'));
})->with([Role::ChiefAccountant, Role::Owner]);

it('lets only the owner see the users list', function () {
    signIn(Role::ChiefAccountant);

    get('/settings/users')->assertForbidden();
    get('/settings/employees')->assertOk();
});

it('prevents the cashier from deactivating contractors, importing, or cancelling documents', function () {
    signIn(Role::Cashier);
    $contractor = Contractor::factory()->create();
    $document = Document::factory()->status(DocumentStatus::Sent)->create();

    patch(route('contractors.toggle', $contractor))->assertForbidden();
    post(route('contractors.import'))->assertForbidden();
    patch(route('documents.status', $document), ['status' => 'cancelled'])->assertForbidden();
    delete(route('documents.destroy', Document::factory()->create()))->assertForbidden();

    expect($contractor->fresh()->is_active)->toBeTrue()
        ->and($document->fresh()->status)->toBe(DocumentStatus::Sent);
});

it('allows the cashier to create contractors and mark documents as sent', function () {
    signIn(Role::Cashier);
    $document = Document::factory()->create();

    post(route('contractors.store'), ['name' => 'Yangi MChJ', 'type' => 'customer'])->assertRedirect(route('contractors.index'));
    patch(route('documents.status', $document), ['status' => 'sent'])->assertRedirect();

    expect(Contractor::where('name', 'Yangi MChJ')->exists())->toBeTrue()
        ->and($document->fresh()->status)->toBe(DocumentStatus::Sent);
});

it('does not allow editing a document that is no longer a draft', function () {
    signIn(Role::Owner);
    $document = Document::factory()->status(DocumentStatus::Paid)->create();

    get(route('documents.edit', $document))->assertForbidden();
});
