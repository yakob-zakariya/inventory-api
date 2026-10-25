<?php

use App\Models\User;

function loginPayload(array $overrides = []): array
{
    return [
        'email' => 'owner@example.com',
        'password' => 'password',
        'device_name' => 'tests',
        ...$overrides,
    ];
}

it('returns a token and the user for valid credentials', function () {
    $user = User::factory()->owner()->create(['email' => 'owner@example.com']);

    $response = $this->postJson('/api/v1/auth/login', loginPayload())
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'owner@example.com')
        ->assertJsonPath('data.user.roles', ['owner'])
        ->assertJsonMissingPath('data.user.password');

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($user->tokens()->first()->name)->toBe('tests');
});

it('issues a token that authenticates later requests', function () {
    User::factory()->owner()->create(['email' => 'owner@example.com']);

    $token = $this->postJson('/api/v1/auth/login', loginPayload())->json('data.token');

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'owner@example.com');
});

it('rejects a wrong password', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $this->postJson('/api/v1/auth/login', loginPayload(['password' => 'wrong-password']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
});

it('rejects an unknown email with the same message', function () {
    $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'nobody@example.com']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
});

it('requires email, password and device name', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', loginPayload(['password' => 'wrong-password']))
            ->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', loginPayload(['password' => 'wrong-password']))
        ->assertTooManyRequests();
});
