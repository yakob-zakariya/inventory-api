<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every named permission and the fixed roles. Safe to run repeatedly.
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

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value)
                ->syncPermissions(array_column($role->permissions(), 'value'));
        }

        $registrar->forgetCachedPermissions();
    }
}
