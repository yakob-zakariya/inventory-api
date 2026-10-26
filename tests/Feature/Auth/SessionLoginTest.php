<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Requests as the web app: its Origin makes Sanctum treat them as cookie-session requests.
 * Laravel skips the CSRF check while tests run; that check is verified by hand (see the branch notes).
 */
function fromWebApp(): TestCase
{
    return test()->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
}

/**
 * Send the next request with the session cookie a previous response set, as a browser would,
 * after forgetting the logged-in user the test app keeps in memory.
 */
function withSessionFrom(TestResponse $response): TestCase
{
    app('auth')->forgetGuards();

    return fromWebApp()->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
}

it('logs the web app in with a session cookie', function () {
    User::factory()->owner()->create(['email' => 'owner@example.com']);

    fromWebApp()->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');

    $login = fromWebApp()->postJson('/api/v1/auth/session', ['email' => 'owner@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'owner@example.com')
        ->assertJsonPath('data.roles', ['owner'])
        ->assertJsonPath('data.permissions.0', 'accounts.manage')
        ->assertJsonMissingPath('data.token')
        ->assertCookie(config('session.cookie'));

    withSessionFrom($login)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'owner@example.com');
});

it('refuses a wrong password', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    fromWebApp()->postJson('/api/v1/auth/session', ['email' => 'owner@example.com', 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);

    $this->app['auth']->forgetGuards();
    fromWebApp()->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('throttles repeated attempts', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    foreach (range(1, 5) as $attempt) {
        fromWebApp()->postJson('/api/v1/auth/session', ['email' => 'owner@example.com', 'password' => 'wrong'])->assertUnprocessable();
    }

    fromWebApp()->postJson('/api/v1/auth/session', ['email' => 'owner@example.com', 'password' => 'password'])
        ->assertTooManyRequests();
});

it('logs the web app out', function () {
    User::factory()->create(['email' => 'owner@example.com']);
    $login = fromWebApp()->postJson('/api/v1/auth/session', ['email' => 'owner@example.com', 'password' => 'password']);

    $logout = withSessionFrom($login)->deleteJson('/api/v1/auth/session')->assertNoContent();

    withSessionFrom($login)->getJson('/api/v1/auth/me')->assertUnauthorized();
    expect($logout->getCookie(config('session.cookie'))->getValue())->not->toBe($login->getCookie(config('session.cookie'))->getValue());
});

it('also ends the session through the token logout route', function () {
    User::factory()->create(['email' => 'owner@example.com']);
    $login = fromWebApp()->postJson('/api/v1/auth/session', ['email' => 'owner@example.com', 'password' => 'password']);

    withSessionFrom($login)->postJson('/api/v1/auth/logout')->assertNoContent();

    withSessionFrom($login)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('offers cookie login only to the web app', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $this->postJson('/api/v1/auth/session', ['email' => 'owner@example.com', 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Cookie login is only for the web app. Use /auth/login to get a token.');
});

it('gives token clients no session', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $response = $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'password'])->assertOk();

    expect($response->getCookie(config('session.cookie')))->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me')->assertOk();
});
