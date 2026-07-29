<?php

namespace Tests;

use App\Support\TestingDatabaseGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestingDatabaseGuard::assertSafe(
            (string) app()->environment(),
            (string) config('database.connections.pgsql.database'),
        );
    }
}
