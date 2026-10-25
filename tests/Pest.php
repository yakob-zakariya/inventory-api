<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against a fresh database seeded with the roles and
| permissions (see TestCase::$seeder).
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Authenticate as a new owner through Sanctum and return the user.
 */
function actingAsOwner(): User
{
    $user = User::factory()->owner()->create();
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Authenticate as a new staff member through Sanctum and return the user.
 */
function actingAsStaff(): User
{
    $user = User::factory()->staff()->create();
    Sanctum::actingAs($user);

    return $user;
}
