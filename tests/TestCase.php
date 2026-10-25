<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the roles and permissions whenever the database is refreshed.
     */
    protected bool $seed = true;

    /**
     * @var class-string
     */
    protected string $seeder = RolesAndPermissionsSeeder::class;
}
