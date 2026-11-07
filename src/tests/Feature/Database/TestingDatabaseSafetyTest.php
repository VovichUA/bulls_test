<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\DatabaseTestCase;
use Tests\Support\DatabaseRefreshProbe;
use Tests\TestCase;

class TestingDatabaseSafetyTest extends TestCase
{
    public function test_mysql_suite_uses_only_the_testing_database(): void
    {
        $connection = DB::connection();

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('bulls_testing', $connection->getDatabaseName());
        $this->assertSame('bulls_testing', $connection->selectOne('SELECT DATABASE() AS name')->name);
    }

    #[DataProvider('unsafeDatabases')]
    public function test_unsafe_database_is_rejected(string $driver, string $database): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only mysql / bulls_testing');

        DatabaseTestCase::assertSafeDatabase($driver, $database);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeDatabases(): array
    {
        return [
            'development database' => ['mysql', 'bulls'],
            'system database' => ['mysql', 'mysql'],
            'different driver' => ['sqlite', 'bulls_testing'],
            'empty database' => ['mysql', ''],
        ];
    }

    public function test_refresh_rejects_development_database_before_migrations(): void
    {
        config(['database.connections.mysql.database' => 'bulls']);
        DB::purge('mysql');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only mysql / bulls_testing');

        (new DatabaseRefreshProbe('probe'))->check($this->app);
    }

    public function test_refresh_rejects_database_url_before_migrations(): void
    {
        config(['database.connections.mysql.url' => 'mysql://bulls:secret@mysql/bulls']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_URL');

        (new DatabaseRefreshProbe('probe'))->check($this->app);
    }

    public function test_refresh_rejects_cached_configuration_before_migrations(): void
    {
        $originalBootstrapPath = $this->app->bootstrapPath();
        $temporaryPath = sys_get_temp_dir().'/bulls-cache-'.bin2hex(random_bytes(8));
        mkdir($temporaryPath.'/cache', 0700, true);
        file_put_contents($temporaryPath.'/cache/config.php', '<?php return [];');
        $this->app->useBootstrapPath($temporaryPath);
        $this->app->forgetInstance('config_loaded_from_cache');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('config:clear');

            (new DatabaseRefreshProbe('probe'))->check($this->app);
        } finally {
            $this->app->useBootstrapPath($originalBootstrapPath);
            $this->app->forgetInstance('config_loaded_from_cache');
            unlink($temporaryPath.'/cache/config.php');
            rmdir($temporaryPath.'/cache');
            rmdir($temporaryPath);
        }
    }
}
