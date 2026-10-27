<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\StoreRoleRequest;
use App\Http\Requests\Api\V1\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RoleController extends Controller
{
    public function __construct(private AccessControl $access) {}

    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->when($request->validated('search'), fn ($query, string $term) => $query->whereLike('name', "%{$term}%"))
            ->orderBy('name')
            ->paginate($request->perPage());

        return RoleResource::collection($roles);
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->access->assertCanGrant($request->user(), $request->validated('permissions'));

        $role = Role::create(['name' => $request->validated('name'), 'guard_name' => 'web']);
        $role->syncPermissions($request->validated('permissions'));

        return (new RoleResource($role->load('permissions')->loadCount('users')))->response()->setStatusCode(201);
    }

    /**
     * Rename the role or replace its permissions. Takes effect on its users' next request.
     */
    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $this->access->assertNotOwnerRole($role);
        $this->access->assertCanManageRole($request->user(), $role);
        $this->access->assertCanGrant($request->user(), $request->validated('permissions', []));

        $this->access->keepingAUserManager(function () use ($request, $role) {
            $role->update($request->safe()->only(['name']));

            if ($request->has('permissions')) {
                $role->syncPermissions($request->validated('permissions'));
            }
        });

        return new RoleResource($role->refresh()->load('permissions')->loadCount('users'));
    }

    /**
     * @throws ConflictException while users still have the role
     */
    public function destroy(Request $request, Role $role): Response
    {
        $this->access->assertNotOwnerRole($role);
        $this->access->assertCanManageRole($request->user(), $role);

        $users = $role->users()->count();

        if ($users > 0) {
            $who = $users === 1 ? '1 user still has' : "{$users} users still have";

            throw new ConflictException("{$who} the role \"{$role->name}\". Give them another role first.");
        }

        $role->delete();

        return response()->noContent();
    }
}
