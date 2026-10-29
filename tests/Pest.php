<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
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

/**
 * Authenticate as a new user whose custom role holds exactly the given permissions.
 *
 * @param  list<string>  $permissions
 */
function actingWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Custom', 'web')->syncPermissions($permissions));
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Run each task in its own forked process, all starting at the same moment, and return
 * what each one returned (or "ExceptionClass: message" if it threw). Concurrency tests only:
 * the children open their own database connections.
 *
 * @param  list<Closure(): string>  $tasks
 * @return list<string>
 */
function inParallel(array $tasks): array
{
    $resultsDir = storage_path('framework/testing/concurrency-'.uniqid());
    mkdir($resultsDir, recursive: true);
    $startAt = microtime(true) + 0.5;

    // Children must open their own connections, not share the parent's socket.
    DB::disconnect();

    $children = [];

    foreach ($tasks as $index => $task) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }

                $outcome = $task();
            } catch (Throwable $e) {
                $outcome = class_basename($e).': '.$e->getMessage();
            }

            file_put_contents("{$resultsDir}/{$index}", $outcome);

            // Skip PHPUnit's shutdown handlers in the child.
            posix_kill(posix_getpid(), SIGKILL);
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $outcomes = array_map(fn ($index) => file_get_contents("{$resultsDir}/{$index}"), array_keys($tasks));
    array_map('unlink', glob("{$resultsDir}/*"));
    rmdir($resultsDir);

    DB::reconnect();

    return $outcomes;
}
