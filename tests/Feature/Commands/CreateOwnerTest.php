<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

it('creates an owner from prompts', function () {
    $this->artisan('inventory:create-owner')
        ->expectsQuestion('Name', 'Shop Owner')
        ->expectsQuestion('Email', 'shop@example.com')
        ->expectsQuestion('Password', 'secret-password')
        ->expectsQuestion('Confirm password', 'secret-password')
        ->expectsOutputToContain('Owner shop@example.com created.')
        ->assertSuccessful();

    $user = User::where('email', 'shop@example.com')->sole();

    expect($user->name)->toBe('Shop Owner')
        ->and($user->hasRole('owner'))->toBeTrue()
        ->and($user->can('users.manage'))->toBeTrue()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('takes name and email from options', function () {
    $this->artisan('inventory:create-owner', ['--name' => 'Shop Owner', '--email' => 'shop@example.com'])
        ->expectsQuestion('Password', 'secret-password')
        ->expectsQuestion('Confirm password', 'secret-password')
        ->assertSuccessful();

    expect(User::where('email', 'shop@example.com')->sole()->hasRole('owner'))->toBeTrue();
});

it('seeds roles and permissions on an empty database', function () {
    RoleModel::query()->delete();
    PermissionModel::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('inventory:create-owner', ['--name' => 'Shop Owner', '--email' => 'shop@example.com'])
        ->expectsQuestion('Password', 'secret-password')
        ->expectsQuestion('Confirm password', 'secret-password')
        ->assertSuccessful();

    expect(RoleModel::count())->toBe(2)
        ->and(User::where('email', 'shop@example.com')->sole()->can('sales.void'))->toBeTrue();
});

it('rejects an email that is already taken', function () {
    User::factory()->create(['email' => 'shop@example.com']);

    $this->artisan('inventory:create-owner', ['--name' => 'Shop Owner', '--email' => 'shop@example.com'])
        ->expectsQuestion('Password', 'secret-password')
        ->expectsQuestion('Confirm password', 'secret-password')
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('rejects a password confirmation that does not match', function () {
    $this->artisan('inventory:create-owner', ['--name' => 'Shop Owner', '--email' => 'shop@example.com'])
        ->expectsQuestion('Password', 'secret-password')
        ->expectsQuestion('Confirm password', 'other-password')
        ->expectsOutputToContain('confirmation does not match')
        ->assertFailed();

    expect(User::count())->toBe(0);
});
