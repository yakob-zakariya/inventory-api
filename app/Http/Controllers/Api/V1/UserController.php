<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexUserRequest;
use App\Http\Requests\Api\V1\ResetUserPasswordRequest;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Users have one role each. Nobody can hand out, or manage someone with, permissions they lack.
 */
class UserController extends Controller
{
    public function __construct(private AccessControl $access) {}

    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with('roles')
            ->when($request->validated('search'), fn ($query, string $term) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$term}%")->orWhereLike('email', "%{$term}%"),
            ))
            ->when($request->validated('role'), fn ($query, string $role) => $query->role($role, 'web'))
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate($request->perPage());

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $role = Role::findByName($request->validated('role'), 'web');
        $this->access->assertCanManageRole($request->user(), $role);

        $user = DB::transaction(function () use ($request, $role) {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $user->assignRole($role);

            return $user;
        });

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    /**
     * Change the user's name, email or role. A new role replaces the old one.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->access->assertCanManageUser($request->user(), $user);
        $role = $request->has('role') ? Role::findByName($request->validated('role'), 'web') : null;

        if ($role) {
            $this->access->assertCanManageRole($request->user(), $role);
        }

        $this->access->keepingAUserManager(function () use ($request, $user, $role) {
            $user->update($request->safe()->only(['name', 'email']));

            if ($role) {
                $user->syncRoles($role);
            }
        });

        return new UserResource($user->refresh()->load('roles'));
    }

    /**
     * Stop the user from logging in and end every login they have. Their history stays.
     */
    public function deactivate(Request $request, User $user): UserResource
    {
        if ($user->is($request->user())) {
            throw new ConflictException('You cannot deactivate yourself.');
        }

        $this->access->assertCanManageUser($request->user(), $user);
        $this->access->keepingAUserManager(fn () => $user->forceFill(['is_active' => false])->save());
        $this->access->endLogins($user);

        return new UserResource($user->load('roles'));
    }

    public function activate(Request $request, User $user): UserResource
    {
        $this->access->assertCanManageUser($request->user(), $user);
        $user->forceFill(['is_active' => true])->save();

        return new UserResource($user->load('roles'));
    }

    /**
     * Set a new password for a user who forgot theirs, ending all of their logins.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): Response
    {
        $this->access->assertCanManageUser($request->user(), $user);
        $user->update(['password' => $request->validated('password')]);
        $this->access->endLogins($user);

        return response()->noContent();
    }
}
