<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Support\Config;

/**
 * A role from spatie/laravel-permission. Its users are always {@see User}: the package would
 * pick the model from the current guard, and API requests run under `sanctum`, which has none.
 */
class Role extends SpatieRole
{
    /**
     * Rows per page in API lists.
     */
    protected $perPage = 10;

    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            Config::modelHasRolesTable(),
            app(PermissionRegistrar::class)->pivotRole,
            Config::morphKey(),
        );
    }
}
