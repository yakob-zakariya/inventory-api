<?php

use App\Models\User;

it('revokes only the token used for the request', function () {
    $user = User::factory()->create();
    $phoneToken = $user->createToken('phone')->plainTextToken;
    $laptopToken = $user->createToken('laptop')->plainTextToken;

    $this->withToken($phoneToken)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['laptop']);

    $this->app['auth']->forgetGuards();
    $this->withToken($phoneToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->withToken($laptopToken)->getJson('/api/v1/auth/me')->assertOk();
});

it('requires authentication', function () {
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});
