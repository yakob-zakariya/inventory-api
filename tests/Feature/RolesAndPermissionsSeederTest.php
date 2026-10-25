<?php

use App\Enums\Permission;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

it('creates every named permission and both roles', function () {
    expect(PermissionModel::pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all())
        ->and(RoleModel::pluck('name')->sort()->values()->all())
        ->toBe(['owner', 'staff']);
});

it('gives the owner every permission', function () {
    expect(RoleModel::findByName(Role::Owner->value)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all());
});

it('gives staff only customer management, sale creation and payment recording', function () {
    expect(RoleModel::findByName(Role::Staff->value)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['customers.manage', 'payments.record', 'sales.create']);
});

it('can run again without creating duplicates', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(PermissionModel::count())->toBe(count(Permission::cases()))
        ->and(RoleModel::count())->toBe(2)
        ->and(RoleModel::findByName(Role::Owner->value)->permissions)->toHaveCount(count(Permission::cases()));
});
