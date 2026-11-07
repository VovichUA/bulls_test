<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    public static function assertSafeDatabase(string $driver, string $database): void
    {
        if ($driver !== 'mysql' || $database !== 'bulls_testing') {
            throw new RuntimeException('Only mysql / bulls_testing is allowed for database tests.');
        }
    }

    final protected function beforeRefreshingDatabase(): void
    {
        if ($this->app->configurationIsCached()) {
            throw new RuntimeException('Run php artisan config:clear before database tests.');
        }

        $defaultConnection = config('database.default');

        if (filled(env('DB_URL')) || filled(config("database.connections.{$defaultConnection}.url"))) {
            throw new RuntimeException('DB_URL must be empty for database tests.');
        }

        $connection = DB::connection();

        self::assertSafeDatabase($connection->getDriverName(), $connection->getDatabaseName());

        $database = $connection->selectOne('SELECT DATABASE() AS name', [], false)->name;

        self::assertSafeDatabase($connection->getDriverName(), $database);
    }
}
