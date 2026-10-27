<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every named permission and the built-in roles. Safe to run repeatedly:
 * edits the shop made to roles other than owner are kept.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value);
        }

        // Model events may be muted (WithoutModelEvents), so the package
        // cannot flush its cache on its own after the inserts above.
        $registrar->forgetCachedPermissions();

        // The owner always gets every permission, including ones added later. Other built-in
        // roles are created once with their defaults; the shop may edit them afterwards.
        RoleModel::findOrCreate(Role::Owner->value)->syncPermissions(Permission::values());

        foreach (Role::cases() as $role) {
            if ($role !== Role::Owner && ! RoleModel::query()->where('name', $role->value)->exists()) {
                RoleModel::findOrCreate($role->value)->syncPermissions(array_column($role->permissions(), 'value'));
            }
        }

        $registrar->forgetCachedPermissions();
    }
}
