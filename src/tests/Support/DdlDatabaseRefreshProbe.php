<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use LogicException;
use Tests\DdlDatabaseTestCase;

class DdlDatabaseRefreshProbe extends DdlDatabaseTestCase
{
    public function check(Application $application): void
    {
        $this->app = $application;
        $this->runDatabaseMigrations();
    }

    protected function refreshTestDatabase(): void
    {
        throw new LogicException('Migration stage reached; probe prevents any database changes.');
    }
}
