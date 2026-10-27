<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Send a request with a real token, as a separate client would.
 */
function withTokenOf(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

/**
 * Log in as a manager whose role has users.manage plus the given permissions.
 *
 * @param  list<string>  $permissions
 */
function actingAsManager(array $permissions = []): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Manager', 'web')->syncPermissions(['users.manage', ...$permissions]));
    Sanctum::actingAs($user);

    return $user;
}

it('edits a user and replaces their role', function () {
    actingAsOwner();
    $user = User::factory()->staff()->create();
    Role::findOrCreate('Cashier', 'web')->syncPermissions(['sales.create']);

    $this->patchJson("/api/v1/users/{$user->id}", ['name' => 'Almaz T.', 'email' => 'almaz@example.com', 'role' => 'Cashier'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Almaz T.')
        ->assertJsonPath('data.email', 'almaz@example.com')
        ->assertJsonPath('data.role', 'Cashier')
        ->assertJsonPath('data.roles', ['Cashier']);
});

it('creates a user with a custom role', function () {
    actingAsOwner();
    Role::findOrCreate('Cashier', 'web')->syncPermissions(['sales.create']);

    $this->postJson('/api/v1/users', [
        'name' => 'Kebede', 'email' => 'kebede@example.com', 'password' => 'secret-password',
        'password_confirmation' => 'secret-password', 'role' => 'Cashier',
    ])->assertCreated()->assertJsonPath('data.role', 'Cashier')->assertJsonPath('data.is_active', true);

    $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-password',
        'password_confirmation' => 'secret-password', 'role' => 'Pilot'])->assertJsonValidationErrors('role');
});

it('does not let a manager give or manage more power than they have', function () {
    actingAsManager(['sales.create']);
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();
    $staff->assignRole(Role::findOrCreate('Seller', 'web')->syncPermissions(['sales.create']));

    $this->patchJson("/api/v1/users/{$staff->id}", ['role' => 'owner'])->assertForbidden();
    $this->patchJson("/api/v1/users/{$owner->id}", ['name' => 'Hacked'])->assertForbidden();
    $this->postJson("/api/v1/users/{$owner->id}/deactivate")->assertForbidden();
    $this->postJson("/api/v1/users/{$owner->id}/password", ['password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertForbidden();

    $this->patchJson("/api/v1/users/{$staff->id}", ['name' => 'Seller One'])->assertOk();
});

it('keeps at least one active user who can manage users', function () {
    $owner = actingAsOwner();

    $this->patchJson("/api/v1/users/{$owner->id}", ['role' => 'staff'])
        ->assertConflict()
        ->assertJsonPath('message', 'This change would leave no active user who can manage users.');

    expect($owner->fresh()->hasRole('owner'))->toBeTrue();
});

it('does not let users deactivate themselves', function () {
    $owner = actingAsOwner();

    $this->postJson("/api/v1/users/{$owner->id}/deactivate")
        ->assertConflict()
        ->assertJsonPath('message', 'You cannot deactivate yourself.');
});

it('deactivates a user: their logins stop at once and they cannot log in', function () {
    actingAsOwner();
    $user = User::factory()->staff()->create(['email' => 'leaving@example.com']);
    $token = $user->createToken('phone')->plainTextToken;
    withTokenOf($token)->getJson('/api/v1/auth/me')->assertOk();

    app('auth')->forgetGuards();
    actingAsOwner();
    $this->postJson("/api/v1/users/{$user->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

    expect($user->tokens()->count())->toBe(0);
    withTokenOf($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => 'leaving@example.com', 'password' => 'password'])
        ->assertJsonValidationErrors(['email' => 'This account is deactivated. Ask the shop owner to reactivate it.']);
});

it('refuses a deactivated user whose token somehow survived', function () {
    $user = User::factory()->staff()->create();
    $token = $user->createToken('phone')->plainTextToken;
    $user->forceFill(['is_active' => false])->save();

    withTokenOf($token)->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'This account is deactivated.');
});

it('refuses a deactivated user on cookie login', function () {
    User::factory()->create(['email' => 'leaving@example.com', 'is_active' => false]);

    $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])
        ->postJson('/api/v1/auth/session', ['email' => 'leaving@example.com', 'password' => 'password'])
        ->assertJsonValidationErrors('email');

    expect(auth('web')->check())->toBeFalse();
});

it('reactivates a user', function () {
    actingAsOwner();
    $user = User::factory()->staff()->create(['is_active' => false]);

    $this->postJson("/api/v1/users/{$user->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
});

it('lets an admin set a new password, ending the user\'s logins', function () {
    actingAsOwner();
    $user = User::factory()->staff()->create(['email' => 'forgot@example.com']);
    $old = $user->createToken('phone')->plainTextToken;

    $this->postJson("/api/v1/users/{$user->id}/password", ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertNoContent();

    withTokenOf($old)->getJson('/api/v1/auth/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => 'forgot@example.com', 'password' => 'brand-new-pass'])->assertOk();
});

it('lets users change their own password, keeping this login only', function () {
    $user = User::factory()->staff()->create();
    $current = $user->createToken('laptop')->plainTextToken;
    $other = $user->createToken('phone')->plainTextToken;

    withTokenOf($current)->postJson('/api/v1/auth/password', [
        'current_password' => 'wrong', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
    ])->assertJsonValidationErrors('current_password');

    withTokenOf($current)->postJson('/api/v1/auth/password', [
        'current_password' => 'password', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
    ])->assertNoContent();

    withTokenOf($current)->getJson('/api/v1/auth/me')->assertOk();
    withTokenOf($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('filters users by role, status and search', function () {
    actingAsOwner();
    User::factory()->staff()->create(['name' => 'Almaz', 'is_active' => false]);
    User::factory()->staff()->create(['name' => 'Kebede']);

    $this->getJson('/api/v1/users?role=staff')->assertJsonPath('data.*.name', ['Almaz', 'Kebede']);
    $this->getJson('/api/v1/users?role=staff&active=0')->assertJsonPath('data.*.name', ['Almaz']);
    $this->getJson('/api/v1/users?search=keb')->assertJsonPath('data.*.name', ['Kebede']);
});

it('refuses a password through the edit endpoint', function () {
    actingAsOwner();
    $user = User::factory()->staff()->create();

    $this->patchJson("/api/v1/users/{$user->id}", ['password' => 'x'])
        ->assertJsonValidationErrors(['password' => 'Use the password endpoint to set a new password.']);
});

it('rejects an unknown role filter', function () {
    actingAsOwner();

    $this->getJson('/api/v1/users?role=Pilot')->assertJsonValidationErrors('role');
});

it('ends the user\'s web sessions when sessions are stored in the database', function () {
    config(['session.driver' => 'database']);
    actingAsOwner();
    $user = User::factory()->staff()->create();
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    DB::table('sessions')->insert(['id' => 'other', 'user_id' => null, 'payload' => '', 'last_activity' => now()->timestamp]);

    $this->postJson("/api/v1/users/{$user->id}/deactivate")->assertOk();

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['other']);
});
