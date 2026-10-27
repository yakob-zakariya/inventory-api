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
     * Refuse to manage a user who holds permissions the acting user does not (for example the owner).
     *
     * @throws AccessDeniedHttpException
     */
    public function assertCanManageUser(User $actor, User $user): void
    {
        $this->assertCanGrant($actor, $user->getAllPermissions()->pluck('name'));
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

    /**
     * End the user's logins: every API token and, with database sessions, every web session.
     * Pass the current token or session id to keep the login making the request.
     */
    public function endLogins(User $user, ?int $exceptTokenId = null, ?string $exceptSessionId = null): void
    {
        $user->tokens()->when($exceptTokenId, fn ($query, int $id) => $query->whereKeyNot($id))->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table'))
                ->where('user_id', $user->id)
                ->when($exceptSessionId, fn ($query, string $id) => $query->where('id', '!=', $id))
                ->delete();
        }
    }
}
