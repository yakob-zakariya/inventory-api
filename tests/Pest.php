<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\ConcurrencyTestCase;
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
| Concurrency tests run on MySQL with committed data (DatabaseMigrations), so that
| separate connections and processes can see the rows and contend for row locks.
*/
pest()->extend(ConcurrencyTestCase::class)
    ->use(DatabaseMigrations::class)
    ->group('mysql')
    ->in('Concurrency');

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
