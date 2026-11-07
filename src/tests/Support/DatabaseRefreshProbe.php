<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use LogicException;
use Tests\DatabaseTestCase;

class DatabaseRefreshProbe extends DatabaseTestCase
{
    public function check(Application $application): void
    {
        $this->app = $application;

        $this->refreshDatabase();
    }

    protected function refreshTestDatabase(): void
    {
        throw new LogicException('Migration stage reached; probe prevents any database changes.');
    }
}
