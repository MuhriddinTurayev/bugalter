<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\followingRedirects;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(fn () => RateLimiter::clear('x'));

it('shows the login page to guests', function () {
    get('/login')->assertOk()->assertSee(__('ui.login.title'))->assertSee(__('ui.login.submit'));
});

it('redirects guests from every application page to the login page', function (string $uri) {
    get($uri)->assertRedirect('/login');
})->with(['/', '/contractors', '/documents', '/bank', '/history', '/reports', '/settings', '/search']);

it('signs in with an email address', function () {
    $user = User::factory()->owner()->create(['email' => 'boss@example.test']);

    post('/login', ['login' => 'BOSS@example.test', 'password' => 'password'])->assertRedirect('/');

    assertAuthenticatedAs($user);
});

it('signs in with a phone number in any common format', function (string $typed) {
    $user = User::factory()->create(['phone' => '+998901234567']);

    post('/login', ['login' => $typed, 'password' => 'password'])->assertRedirect('/');

    assertAuthenticatedAs($user);
})->with(['+998901234567', '998 90 123 45 67', '90-123-45-67', '(90) 123 45 67']);

it('rejects a wrong password and inactive users', function () {
    $user = User::factory()->create(['email' => 'a@example.test']);

    post('/login', ['login' => 'a@example.test', 'password' => 'wrong'])
        ->assertSessionHasErrors('login');
    assertGuest();

    $user->update(['is_active' => false]);
    post('/login', ['login' => 'a@example.test', 'password' => 'password'])->assertSessionHasErrors('login');
    assertGuest();
});

it('throttles repeated failed attempts', function () {
    User::factory()->create(['email' => 'a@example.test']);

    foreach (range(1, 5) as $_) {
        post('/login', ['login' => 'a@example.test', 'password' => 'wrong']);
    }

    post('/login', ['login' => 'a@example.test', 'password' => 'password'])->assertSessionHasErrors('login');
    assertGuest();
});

it('signs out', function () {
    actingAs(User::factory()->create());

    post('/logout')->assertRedirect('/login');

    assertGuest();
});

it('redirects signed-in users away from the login page', function () {
    actingAs(User::factory()->create());

    get('/login')->assertRedirect('/');
});

it('switches language and remembers it on the user', function () {
    $user = User::factory()->create(['locale' => 'uz']);
    actingAs($user);

    followingRedirects()->get('/locale/ru')->assertSee('Добро пожаловать');

    expect($user->fresh()->locale)->toBe('ru');

    get('/locale/xx')->assertNotFound();
});

it('uses the saved locale of the user on a new session', function () {
    actingAs(User::factory()->create(['locale' => 'ru']));

    get('/')->assertSee('Добро пожаловать');
});

it('prefers Uzbek by default', function () {
    actingAs(User::factory()->owner()->create(['locale' => null]));

    get('/')->assertSee('Xush kelibsiz');
});

it('has three roles with the expected menu rights', function () {
    expect(Role::Cashier->canViewReports())->toBeFalse()
        ->and(Role::ChiefAccountant->canViewReports())->toBeTrue()
        ->and(Role::Owner->canManageUsers())->toBeTrue()
        ->and(Role::ChiefAccountant->canManageUsers())->toBeFalse();
});
