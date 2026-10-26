<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * List users with their roles.
     */
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        return UserResource::collection(User::with('roles')->orderBy('name')->paginate($request->perPage()));
    }

    /**
     * Create a user with one of the fixed roles.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $user->assignRole($request->validated('role'));

            return $user;
        });

        return (new UserResource($user->load('roles')))
            ->response()
            ->setStatusCode(201);
    }
}
