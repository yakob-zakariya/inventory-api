<?php

namespace App\Http\Resources;

use App\Enums\Role as BuiltInRole;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role and its permission names. The owner role is `is_locked`: it always has every permission.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
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
            'permissions' => $this->permissions->pluck('name')->sort()->values(),
            'users_count' => $this->whenCounted('users'),
            'is_locked' => $this->name === BuiltInRole::Owner->value,
            'created_at' => $this->created_at,
        ];
    }
}
