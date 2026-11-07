<?php

namespace Tests\Feature\Database;

use App\Exceptions\SchemaLockTimeoutException;
use App\Exceptions\SchemaManagerException;
use App\Models\ColumnMapping;
use App\Models\SyncTarget;
use App\Services\SchemaManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DdlDatabaseTestCase;

class SchemaManagerTest extends DdlDatabaseTestCase
{
    public function test_creates_snapshot_contract_and_all_default_types(): void
    {
        $target = $this->target();
        foreach (['string', 'text', 'integer', 'decimal', 'date', 'datetime', 'boolean'] as $type) {
            $this->mapping($target, 'value_'.$type, $type);
        }
        $this->manager()->ensure($target);
        $columns = $this->columns();
        foreach (['value_string' => 'varchar(255)', 'value_text' => 'text', 'value_integer' => 'bigint', 'value_decimal' => 'decimal(15,4)', 'value_date' => 'date', 'value_datetime' => 'datetime', 'value_boolean' => 'tinyint(1)', 'id' => 'bigint unsigned', 'sync_target_id' => 'bigint unsigned', 'source_row' => 'bigint unsigned', 'snapshot_date' => 'date', 'row_hash' => 'char(64)'] as $column => $type) {
            $this->assertSame($type, $columns[$column]['type']);
        }
        $this->assertTrue($columns['id']['auto_increment']);
        foreach (['value_string', 'value_decimal', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue($columns[$column]['nullable']);
        }
        $this->assertTrue(Schema::hasIndex('stage_sales', ['sync_target_id', 'snapshot_date', 'source_row'], 'unique'));
        $this->assertTrue(Schema::hasIndex('stage_sales', ['id'], 'primary'));
    }

    public function test_adds_nullable_column_without_losing_history_and_is_idempotent(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name');
        $this->manager()->ensure($target);
        DB::table('stage_sales')->insert(['sync_target_id' => $target->id, 'snapshot_date' => '2026-11-06', 'source_row' => 4, 'row_hash' => str_repeat('a', 64), 'name' => 'Tea']);
        $target->load('mappings');
        $this->mapping($target, 'price', 'decimal', ['precision' => 12, 'scale' => 2]);
        $this->manager()->ensure($target);
        $this->manager()->ensure($target);
        $this->assertSame('decimal(12,2)', $this->columns()['price']['type']);
        $this->assertTrue($this->columns()['price']['nullable']);
        $this->assertDatabaseHas('stage_sales', ['name' => 'Tea', 'price' => null, 'source_row' => 4]);
        $this->assertSame(1, DB::table('stage_sales')->count());
    }

    public function test_supports_length_options_and_long_identifiers(): void
    {
        $name = 'a'.str_repeat('b', 63);
        $target = $this->target($name);
        $this->mapping($target, $name, 'string', ['length' => 42]);
        $this->manager()->ensure($target);
        $this->manager()->ensure($target);
        $this->assertSame('varchar(42)', $this->columns($name)[$name]['type']);
    }

    #[DataProvider('unsafeTables')]
    public function test_rejects_unsafe_table_names_before_ddl(string $name): void
    {
        $target = $this->target($name);
        $this->mapping($target, 'name');
        $before = Schema::getTableListing();
        try {
            $this->manager()->ensure($target);
            $this->fail('Unsafe table accepted.');
        } catch (SchemaManagerException) {
            $this->assertSame($before, Schema::getTableListing());
        }
    }

    public static function unsafeTables(): array
    {
        return array_map(fn (string $name): array => [$name], ['users', 'migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sheet_sources', 'sheet_custom', 'sync_targets', 'sync_custom', 'column_mappings', 'column_mapping_custom', 'Sales', '1sales', 'sales.items', 'sales;drop', 'sales`', "sales\n", 'продажі', '']);
    }

    #[DataProvider('unsafeColumns')]
    public function test_rejects_reserved_or_invalid_columns_before_creating_table(string $name): void
    {
        $target = $this->target();
        $this->mapping($target, 'good');
        $this->mapping($target, $name);
        try {
            $this->manager()->ensure($target);
            $this->fail('Unsafe column accepted.');
        } catch (SchemaManagerException) {
            $this->assertFalse(Schema::hasTable('stage_sales'));
        }
    }

    public static function unsafeColumns(): array
    {
        return array_map(fn (string $name): array => [$name], ['id', 'sync_target_id', 'snapshot_date', 'source_row', 'row_hash', 'created_at', 'updated_at', 'source_id', 'sync_run_id', 'Bad', 'price.value', 'price`', "price\n", '']);
    }

    #[DataProvider('invalidOptions')]
    public function test_rejects_invalid_type_options_before_ddl(string $type, array $options): void
    {
        $target = $this->target();
        $this->mapping($target, 'value', $type, $options);
        $this->expectException(SchemaManagerException::class);
        try {
            $this->manager()->ensure($target);
        } finally {
            $this->assertFalse(Schema::hasTable('stage_sales'));
        }
    }

    public static function invalidOptions(): array
    {
        return [['string', ['length' => 0]], ['string', ['length' => 256]], ['string', ['length' => '255']], ['decimal', ['precision' => 66]], ['decimal', ['precision' => 0]], ['decimal', ['scale' => -1]], ['decimal', ['scale' => 31]], ['decimal', ['precision' => 3, 'scale' => 4]], ['decimal', ['scale' => null]]];
    }

    public function test_rejects_unowned_existing_table_without_changes(): void
    {
        Schema::create('stage_sales', fn (Blueprint $table) => $table->string('business'));
        DB::table('stage_sales')->insert(['business' => 'keep']);
        $target = $this->target();
        $this->mapping($target, 'name');
        $this->expectException(SchemaManagerException::class);
        try {
            $this->manager()->ensure($target);
        } finally {
            $this->assertFalse(Schema::hasColumn('stage_sales', 'name'));
            $this->assertDatabaseHas('stage_sales', ['business' => 'keep']);
        }
    }

    public function test_shared_table_rejects_conflicting_type_before_adding_any_columns(): void
    {
        $first = $this->target();
        $this->mapping($first, 'price', 'decimal');
        $this->manager()->ensure($first);
        $second = $this->target();
        $this->mapping($second, 'new_column');
        $this->mapping($second, 'price', 'text');
        $this->expectException(SchemaManagerException::class);
        try {
            $this->manager()->ensure($second);
        } finally {
            $this->assertFalse(Schema::hasColumn('stage_sales', 'new_column'));
            $this->assertSame('decimal(15,4)', $this->columns()['price']['type']);
        }
    }

    public function test_shared_table_adds_columns_and_preserves_removed_mappings_columns(): void
    {
        $first = $this->target();
        $mapping = $this->mapping($first, 'name');
        $this->manager()->ensure($first);
        $mapping->delete();
        $second = $this->target();
        $this->mapping($second, 'price', 'integer');
        $this->manager()->ensure($second);
        $this->assertTrue(Schema::hasColumns('stage_sales', ['name', 'price']));
    }

    public function test_detects_tampered_snapshot_index(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name');
        $this->manager()->ensure($target);
        Schema::table('stage_sales', fn (Blueprint $table) => $table->dropUnique('snapshot_rows_unique'));
        $this->expectException(SchemaManagerException::class);
        $this->manager()->ensure($target);
    }

    public function test_detects_tampered_service_column(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name');
        $this->manager()->ensure($target);
        Schema::table('stage_sales', fn (Blueprint $table) => $table->dropColumn('row_hash'));
        $this->expectException(SchemaManagerException::class);
        $this->manager()->ensure($target);
    }

    public function test_rejects_transaction_without_committing_it(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name');
        DB::beginTransaction();
        try {
            $this->manager()->ensure($target);
            $this->fail('DDL in transaction accepted.');
        } catch (SchemaManagerException) {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::getPdo()->inTransaction());
            $this->assertFalse(Schema::hasTable('stage_sales'));
        } finally {
            DB::rollBack();
        }
    }

    public function test_requires_persisted_configuration_and_mappings(): void
    {
        $this->expectException(SchemaManagerException::class);
        $this->manager()->ensure(new SyncTarget(['target_table' => 'stage_sales']));
    }

    public function test_requires_at_least_one_mapping(): void
    {
        $this->expectException(SchemaManagerException::class);
        $this->manager()->ensure($this->target());
    }

    public function test_concurrent_session_lock_blocks_ddl_and_is_released_after_ensure(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name');
        config(['database.connections.schema_lock_test' => config('database.connections.mysql')]);
        $other = DB::connection('schema_lock_test');
        $lock = 'sheets_schema:'.sha1(DB::getDatabaseName()."\0stage_sales");
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $other->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $this->assertSame(1, (int) $other->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired);
        try {
            (new SchemaManager(lockTimeoutSeconds: 0))->ensure($target);
            $this->fail('Concurrent schema lock was ignored.');
        } catch (SchemaLockTimeoutException) {
            $this->assertFalse(Schema::hasTable('stage_sales'));
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
            DB::disconnect('schema_lock_test');
        }
        $this->manager()->ensure($target);
        $this->assertSame(1, (int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS free', [$lock])->free);
        $this->assertTrue(Schema::hasColumn('stage_sales', 'name'));
    }

    public function test_releases_lock_when_existing_schema_is_incompatible(): void
    {
        Schema::create('stage_sales', fn (Blueprint $table) => $table->id());
        $target = $this->target();
        $this->mapping($target, 'name');
        $lock = 'sheets_schema:'.sha1(DB::getDatabaseName()."\0stage_sales");
        try {
            $this->manager()->ensure($target);
            $this->fail('Unmanaged schema accepted.');
        } catch (SchemaManagerException) {
            $this->assertSame(1, (int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS free', [$lock])->free);
        }
    }

    public function test_unknown_type_is_rejected_before_ddl(): void
    {
        $target = $this->target();
        $mapping = $this->mapping($target, 'value');
        DB::table('column_mappings')->where('id', $mapping->id)->update(['data_type' => 'arbitrary_sql_type']);
        $this->expectException(SchemaManagerException::class);
        try {
            $this->manager()->ensure($target);
        } finally {
            $this->assertFalse(Schema::hasTable('stage_sales'));
        }
    }

    public function test_schema_operation_uses_writer_for_configuration_and_metadata(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name');
        DB::connection()->setReadPdo(function (): never {
            throw new \RuntimeException('Replica reads are forbidden in this test.');
        });
        try {
            $this->manager()->ensure($target);
        } finally {
            DB::connection()->setReadPdo(null);
        }
        $this->assertTrue(Schema::hasColumn('stage_sales', 'name'));
    }

    public function test_lost_lock_session_aborts_instead_of_reconnecting_and_running_ddl(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name');
        $session = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        config(['database.connections.schema_killer' => config('database.connections.mysql')]);
        $other = DB::connection('schema_killer');
        $killed = false;
        DB::listen(function (QueryExecuted $query) use ($session, $other, &$killed): void {
            if (! $killed && str_contains($query->sql, 'GET_LOCK')) {
                $killed = true;
                $other->statement('KILL CONNECTION '.$session);
            }
        });
        try {
            $this->manager()->ensure($target);
            $this->fail('DDL continued after the locked session was lost.');
        } catch (SchemaManagerException) {
            $this->assertTrue($killed);
        } finally {
            DB::reconnect();
            DB::disconnect('schema_killer');
        }
        $this->assertFalse(Schema::hasTable('stage_sales'));
    }

    public function test_rejects_native_pdo_transaction_without_laravel_transaction_counter(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name');
        $pdo = DB::getPdo();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::transactionLevel());
            $this->manager()->ensure($target);
            $this->fail('Native PDO transaction was implicitly committed.');
        } catch (SchemaManagerException) {
            $this->assertTrue($pdo->inTransaction());
            $this->assertFalse(Schema::hasTable('stage_sales'));
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_large_row_schema_reports_actionable_size_error(): void
    {
        $target = $this->target();
        for ($index = 0; $index < 70; $index++) {
            $this->mapping($target, 'value_'.$index);
        }
        try {
            $this->manager()->ensure($target);
            $this->fail('Expected MySQL row-size rejection.');
        } catch (SchemaManagerException $exception) {
            $this->assertStringContainsString('TEXT', $exception->getMessage());
            $this->assertFalse(Schema::hasTable('stage_sales'));
        }
    }

    private function target(string $table = 'stage_sales'): SyncTarget
    {
        return SyncTarget::factory()->create(['target_table' => $table]);
    }

    private function mapping(SyncTarget $target, string $column, string $type = 'string', ?array $options = null): ColumnMapping
    {
        return ColumnMapping::factory()->for($target, 'target')->create(['target_column' => $column, 'data_type' => $type, 'type_options' => $options, 'nullable' => false]);
    }

    private function manager(): SchemaManager
    {
        return app(SchemaManager::class);
    }

    private function columns(string $table = 'stage_sales'): array
    {
        return array_column(Schema::getColumns($table), null, 'name');
    }
}
