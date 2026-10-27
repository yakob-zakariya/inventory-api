<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    /**
     * Every permission a role can include, with a label and a group for the role editor.
     */
    public function index(): AnonymousResourceCollection
    {
        return PermissionResource::collection(Permission::cases());
    }
}
