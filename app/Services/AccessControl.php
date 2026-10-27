<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\ConflictException;
use App\Models\Role as RoleModel;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The safety rules around roles and users, shared by every endpoint that changes them:
 * no escalation, the owner role is fixed, and the shop always keeps an active user manager.
 */
class AccessControl
{
    /**
     * Refuse to grant (or manage someone holding) a permission the acting user does not have.
     *
     * @param  iterable<string>  $permissions
     *
     * @throws AccessDeniedHttpException
     */
    public function assertCanGrant(User $actor, iterable $permissions): void
    {
        $held = $actor->getAllPermissions()->pluck('name')->all();
        $missing = array_values(array_diff(collect($permissions)->all(), $held));

        if ($missing !== []) {
            throw new AccessDeniedHttpException('You cannot hand out permissions you do not have: '.implode(', ', $missing).'.');
        }
    }

    /**
     * @throws AccessDeniedHttpException when the role gives more than the actor holds
     */
    public function assertCanManageRole(User $actor, RoleModel $role): void
    {
        $this->assertCanGrant($actor, $role->permissions->pluck('name'));
    }

    /**
     * @throws ConflictException for the owner role, which always has every permission
     */
    public function assertNotOwnerRole(RoleModel $role): void
    {
        if ($role->name === Role::Owner->value) {
            throw new ConflictException('The owner role always has every permission and cannot be changed or deleted.');
        }
    }

    /**
     * Run a change in a transaction and undo it if no active user could manage users afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     *
     * @throws ConflictException
     */
    public function keepingAUserManager(Closure $change): mixed
    {
        try {
            return DB::transaction(function () use ($change) {
                $result = $change();

                if (! User::query()->active()->permission(Permission::UsersManage->value)->exists()) {
                    throw new ConflictException('This change would leave no active user who can manage users.');
                }

                return $result;
            });
        } finally {
            // The permission cache may have been rebuilt inside a transaction that was rolled back.
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
