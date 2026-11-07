<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class DatabaseTestCase extends GuardedDatabaseTestCase
{
    use RefreshDatabase;

    final protected function beforeRefreshingDatabase(): void
    {
        $this->assertSafeDatabaseBeforeMigrations();
    }
}
