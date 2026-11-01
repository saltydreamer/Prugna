<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests using RefreshDatabase start with the roles and permissions in place.
     */
    protected bool $seed = true;

    protected string $seeder = PermissionSeeder::class;
}
