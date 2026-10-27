<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Whether to include every permission name the user has (directly or through roles).
     */
    private bool $includePermissions = false;

    /**
     * Include the user's effective permission names, e.g. for `GET /auth/me`.
     */
    public function withPermissions(): static
    {
        $this->includePermissions = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->whenLoaded('roles', fn () => $this->roles->first()?->name),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'is_active' => $this->is_active,
            'permissions' => $this->when(
                $this->includePermissions,
                fn () => $this->getAllPermissions()->pluck('name')->sort()->values(),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
