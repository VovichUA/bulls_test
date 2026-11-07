<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class DatabaseIsolationTest extends DatabaseTestCase
{
    public function test_refresh_database_migrates_only_the_testing_database(): void
    {
        $this->assertSame('bulls_testing', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertDatabaseHas('migrations', [
            'migration' => '0001_01_01_000000_create_users_table',
        ]);
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
    }
}
