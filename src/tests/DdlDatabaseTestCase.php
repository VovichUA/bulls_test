<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;

abstract class DdlDatabaseTestCase extends GuardedDatabaseTestCase
{
    use DatabaseMigrations;

    final protected function beforeRefreshingDatabase(): void
    {
        $this->assertSafeDatabaseBeforeMigrations();
    }
}
