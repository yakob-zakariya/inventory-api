<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function newUserPayload(array $overrides = []): array
{
    return [
        'name' => 'New Staff',
        'email' => 'new.staff@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
        'role' => 'staff',
        ...$overrides,
    ];
}

it('lets the owner list users with their roles', function () {
    actingAsOwner();
    User::factory()->staff()->count(2)->create();

    $this->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'roles', 'created_at']], 'links', 'meta'])
        ->assertJsonMissingPath('data.0.password')
        ->assertJsonMissingPath('data.0.permissions');
});

it('lets the owner create a user with a role', function () {
    actingAsOwner();

    $this->postJson('/api/v1/users', newUserPayload())
        ->assertCreated()
        ->assertJsonPath('data.email', 'new.staff@example.com')
        ->assertJsonPath('data.roles', ['staff']);

    $user = User::where('email', 'new.staff@example.com')->sole();

    expect($user->hasRole('staff'))->toBeTrue()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('forbids staff from listing or creating users', function () {
    actingAsStaff();

    $this->getJson('/api/v1/users')->assertForbidden();
    $this->postJson('/api/v1/users', newUserPayload())->assertForbidden();

    expect(User::where('email', 'new.staff@example.com')->exists())->toBeFalse();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/users')->assertUnauthorized();
    $this->postJson('/api/v1/users', newUserPayload())->assertUnauthorized();
});

it('validates the new user', function (array $overrides, string $field) {
    actingAsOwner();

    $this->postJson('/api/v1/users', newUserPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'unconfirmed password' => [['password_confirmation' => 'different-password'], 'password'],
    'unknown role' => [['role' => 'admin'], 'role'],
]);

it('rejects an email that is already taken', function () {
    actingAsOwner();
    User::factory()->create(['email' => 'new.staff@example.com']);

    $this->postJson('/api/v1/users', newUserPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});
