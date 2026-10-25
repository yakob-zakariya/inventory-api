<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs against the MySQL from docker-compose.yml, because SQLite ignores row locks.
 * Every test is skipped when that database cannot be reached.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    /**
     * Point the app at MySQL before the database traits migrate anything.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql_testing');

        if (! static::mysqlIsReachable()) {
            $this->markTestSkipped('MySQL test database not reachable. Run `docker compose up -d mysql-test`.');
        }

        return $app;
    }

    private static function mysqlIsReachable(): bool
    {
        static $reachable = null;

        if ($reachable === null) {
            try {
                DB::connection('mysql_testing')->getPdo();
                $reachable = true;
            } catch (Throwable) {
                $reachable = false;
            }
        }

        return $reachable;
    }
}
