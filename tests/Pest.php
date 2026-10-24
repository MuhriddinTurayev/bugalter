<?php

use App\Actions\SaveDocument;
use App\Enums\DocumentStatus;
use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Http\Middleware\PlatformTwoFactor;
use App\Models\Account;
use App\Models\Category;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\PlatformStaff;
use App\Models\TaxDeadline;
use App\Models\TaxRule;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Tax\TaxRuleBook;
use Database\Seeders\TaxRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/** A signed-in user with the given role. */
function signIn(Role $role = Role::Owner): User
{
    $user = User::factory()->role($role)->create();
    test()->actingAs($user);

    return $user;
}

/** URL on the platform admin host, e.g. admin('/companies'). */
function admin(string $path = ''): string
{
    return 'https://'.config('platform.admin_host').$path;
}

/** URL on a company host, e.g. company('acme', '/login'). */
function company(string $slug, string $path = ''): string
{
    return 'https://'.$slug.'.'.config('platform.domain').$path;
}

/** Platform staff signed in on the platform guard, with two-factor already verified in the session. */
function signInStaff(PlatformRole $role = PlatformRole::SuperAdmin, bool $verified = true): PlatformStaff
{
    $factory = PlatformStaff::factory()->withTwoFactor();
    $staff = ($role === PlatformRole::Support ? $factory->support() : $factory)->create();

    test()->actingAs($staff, 'platform');

    if ($verified) {
        test()->withSession([PlatformTwoFactor::SESSION_VERIFIED => true]);
    }

    return $staff;
}

/**
 * Company databases are SQLite files in tests. They are removed again by cleanUpTenants().
 */
function useSqliteTenancy(): void
{
    config(['tenancy.database.suffix' => '.sqlite']);
}

/** Back to the central context between requests of different hosts in one test. */
function resetRequestContext(): void
{
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store'); // otherwise session() keeps reading the dropped store
}

/** Loads docs/tax/rules.json into the rules tables, optionally marking everything verified. */
function seedTaxRules(bool $verified = false): void
{
    app(TaxRuleSeeder::class)->sync();

    if ($verified) {
        TaxRule::query()->update(['status' => 'verified', 'verified_by' => 'Test', 'verified_at' => now()]);
        TaxDeadline::query()->update(['status' => 'verified', 'verified_by' => 'Test', 'verified_at' => now()]);
    }

    app(TaxRuleBook::class)->forget();
}

/** A sent (or paid) document with one line, through the real SaveDocument action. Amounts in soʻm. */
function bookDocument(string $direction, int $netSom, int $vatBp, string $date, DocumentStatus $status = DocumentStatus::Sent, ?Contractor $contractor = null): Document
{
    $document = app(SaveDocument::class)([
        'type' => 'invoice',
        'direction' => $direction,
        'contractor_id' => ($contractor ?? Contractor::factory()->create())->id,
        'issue_date' => $date,
        'due_date' => null,
        'notes' => null,
        'lines' => [['name' => 'Xizmat', 'unit' => null, 'quantity' => '1.000', 'unit_price_minor' => $netSom * 100, 'vat_rate_bp' => $vatBp]],
    ]);

    $document->update(['status' => $status]);

    return $document;
}

/** A completed money movement. A category key creates the category when it does not exist. Amounts in soʻm. */
function bookTransaction(string $type, int $som, string $date, ?string $categoryKey = 'sales', array $attributes = []): Transaction
{
    $account = Account::query()->first() ?? Account::factory()->create();
    $category = $categoryKey === null ? null : Category::query()->firstOrCreate(
        ['key' => $categoryKey],
        ['name' => $categoryKey, 'type' => $type],
    );

    return Transaction::create($attributes + [
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'type' => $type,
        'amount_minor' => $som * 100,
        'occurred_at' => $date.' 12:00:00',
        'status' => 'completed',
    ]);
}

function cleanUpTenants(): void
{
    resetRequestContext();

    Tenant::query()->get()->each(function (Tenant $tenant) {
        $tenant->delete();
    });

    foreach (glob(database_path('tenant*.sqlite')) ?: [] as $file) {
        @unlink($file);
    }

    foreach (glob(storage_path('tenant*'), GLOB_ONLYDIR) ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
}
