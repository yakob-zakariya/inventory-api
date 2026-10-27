<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/**
 * Log in as a new user whose only role has the given permissions.
 *
 * @param  list<string>  $permissions
 */
function actingWithRole(string $role, array $permissions): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate($role, 'web')->syncPermissions($permissions));
    Sanctum::actingAs($user);

    return $user;
}

it('creates a role with chosen permissions', function () {
    actingAsOwner();

    $this->postJson('/api/v1/roles', ['name' => 'Cashier', 'permissions' => ['sales.create', 'payments.record']])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Cashier')
        ->assertJsonPath('data.permissions', ['payments.record', 'sales.create'])
        ->assertJsonPath('data.users_count', 0)
        ->assertJsonPath('data.is_locked', false);
});

it('validates a role', function (array $body, string $field) {
    actingAsOwner();

    $this->postJson('/api/v1/roles', $body)->assertJsonValidationErrors($field);
})->with([
    'unknown permission' => [['name' => 'X', 'permissions' => ['sales.fly']], 'permissions.0'],
    'repeated permission' => [['name' => 'X', 'permissions' => ['sales.create', 'sales.create']], 'permissions.0'],
    'name taken' => [['name' => 'staff', 'permissions' => []], 'name'],
    'no permissions list' => [['name' => 'X'], 'permissions'],
]);

it('lists roles with how many users have them', function () {
    actingAsOwner();
    User::factory()->count(2)->staff()->create();

    $this->getJson('/api/v1/roles')
        ->assertJsonPath('data.0.name', 'owner')
        ->assertJsonPath('data.0.is_locked', true)
        ->assertJsonPath('data.0.users_count', 1)
        ->assertJsonPath('data.1.name', 'staff')
        ->assertJsonPath('data.1.users_count', 2);
});

it('never changes or deletes the owner role', function () {
    actingAsOwner();
    $owner = Role::findByName('owner', 'web');

    $this->patchJson("/api/v1/roles/{$owner->id}", ['permissions' => []])
        ->assertConflict()
        ->assertJsonPath('message', 'The owner role always has every permission and cannot be changed or deleted.');
    $this->deleteJson("/api/v1/roles/{$owner->id}")->assertConflict();
});

it('applies changed permissions on the next request without logging in again', function () {
    actingAsOwner();
    $token = User::factory()->staff()->create()->createToken('test')->plainTextToken;
    $asStaff = function () use ($token) {
        app('auth')->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/reports/summary');
    };

    $asStaff()->assertForbidden();

    app('auth')->forgetGuards();
    actingAsOwner();
    $this->patchJson('/api/v1/roles/'.Role::findByName('staff', 'web')->id, ['permissions' => ['sales.create', 'reports.view']])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['reports.view', 'sales.create']);

    $asStaff()->assertOk();
});

it('renames a role', function () {
    actingAsOwner();
    $staff = Role::findByName('staff', 'web');

    $this->patchJson("/api/v1/roles/{$staff->id}", ['name' => 'Shop assistant'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Shop assistant')
        ->assertJsonPath('data.permissions', ['customers.manage', 'payments.record', 'sales.create']);
});

it('deletes a role only when nobody has it', function () {
    actingAsOwner();
    $cashier = Role::findOrCreate('Cashier', 'web');
    User::factory()->count(3)->create()->each->assignRole($cashier);

    $this->deleteJson("/api/v1/roles/{$cashier->id}")
        ->assertConflict()
        ->assertJsonPath('message', '3 users still have the role "Cashier". Give them another role first.');

    $empty = Role::findOrCreate('Unused', 'web');
    $this->deleteJson("/api/v1/roles/{$empty->id}")->assertNoContent();
    expect(Role::where('name', 'Unused')->exists())->toBeFalse();
});

it('does not let a manager hand out permissions they do not have', function () {
    actingWithRole('Manager', ['users.manage', 'sales.create', 'sales.void']);

    $this->postJson('/api/v1/roles', ['name' => 'Cashier', 'permissions' => ['sales.create', 'reports.view', 'payments.void']])
        ->assertForbidden()
        ->assertJsonPath('message', 'You cannot hand out permissions you do not have: reports.view, payments.void.');

    $this->postJson('/api/v1/roles', ['name' => 'Cashier', 'permissions' => ['sales.create']])->assertCreated();
});

it('does not let a manager edit or delete a role stronger than theirs', function () {
    actingWithRole('Manager', ['users.manage', 'sales.create']);
    $accountant = Role::findOrCreate('Accountant', 'web')->syncPermissions(['reports.view']);

    $this->patchJson("/api/v1/roles/{$accountant->id}", ['name' => 'Bookkeeper'])->assertForbidden();
    $this->deleteJson("/api/v1/roles/{$accountant->id}")->assertForbidden();
});

it('refuses a change that leaves nobody able to manage users', function () {
    $manager = actingWithRole('Admin', ['users.manage', 'sales.create']);

    $this->patchJson('/api/v1/roles/'.Role::findByName('Admin', 'web')->id, ['permissions' => ['sales.create']])
        ->assertConflict()
        ->assertJsonPath('message', 'This change would leave no active user who can manage users.');

    expect($manager->fresh()->can('users.manage'))->toBeTrue();
});

it('is for users who manage users', function () {
    actingAsStaff();

    $this->getJson('/api/v1/roles')->assertForbidden();
    $this->postJson('/api/v1/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();
});
