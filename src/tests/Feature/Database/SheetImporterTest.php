<?php

namespace Tests\Feature\Database;

use App\Enums\SyncRunStatus;
use App\Exceptions\GoogleSheetsApiException;
use App\Exceptions\GoogleSheetsException;
use App\Exceptions\SheetImportException;
use App\Models\ColumnMapping;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\SheetRowMapper;
use App\Services\SheetImporter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DdlDatabaseTestCase;

class SheetImporterTest extends DdlDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(12, 0));
    }

    #[DataProvider('columnLayouts')]
    public function test_imports_reordered_physical_columns_and_ignores_unmapped_extras(array $values): void
    {
        $target = $this->target();
        $target->tab->update(['header_row' => 3]);
        $this->mapping($target, 'Product Name', 'name');
        $this->mapping($target, 'Price', 'product_price', 'decimal');
        $this->mapping($target, 'Amount', 'amount', 'integer');
        $mapper = app(SheetRowMapper::class);
        $headers = $mapper->headers($values[2]);
        $this->data($headers, $mapper->rows($values, 3), $mapper->ambiguous($headers));

        $run = $this->importer()->importTarget($target);

        $this->assertSame(SyncRunStatus::Succeeded, $run->status);
        $this->assertSame(2, $run->rows_processed);
        $this->assertDatabaseHas('import_sales', ['source_row' => 4, 'name' => 'Tea', 'product_price' => '12.5000', 'amount' => 2]);
        $this->assertDatabaseHas('import_sales', ['source_row' => 6, 'name' => 'Coffee', 'product_price' => '20.0000', 'amount' => 3]);
        $this->assertSame(2, DB::table('import_sales')->count());
        foreach (['notes', 'total', 'price', 'product_name'] as $unmapped) {
            $this->assertFalse(Schema::hasColumn('import_sales', $unmapped));
        }
        Http::assertNothingSent();
    }

    public static function columnLayouts(): array
    {
        return [
            'original order' => [[[], [], ['Product Name', 'Price', 'Amount'], ['Tea', 12.5, 2], [], ['Coffee', 20, 3]]],
            'reordered with extra and ambiguous unselected columns' => [[[], [], ['Notes', 'Amount', ' PRICE ', 'Product  Name', '', 'Total', ' total '], ['Ignore', 2, 12.5, 'Tea', 'Unnamed', 1, 2], [], ['', 3, 20, 'Coffee']]],
        ];
    }

    public function test_new_mapping_adds_physical_column_and_preserves_previous_day(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $this->data(['name'], [2 => ['name' => 'Tea']]);
        $this->assertSame(SyncRunStatus::Succeeded, $this->importer()->importTarget($target, '2026-11-06')->status);
        $this->assertFalse(Schema::hasColumn('import_sales', 'product_price'));

        $this->mapping($target, 'price', 'product_price', 'decimal', true);
        $this->data(['price', 'name'], [2 => ['price' => 12.5, 'name' => 'Tea']]);
        $run = $this->importer()->importTarget($target);

        $this->assertSame(SyncRunStatus::Succeeded, $run->status);
        $this->assertTrue(Schema::hasColumn('import_sales', 'product_price'));
        $this->assertDatabaseHas('import_sales', ['snapshot_date' => '2026-11-06', 'name' => 'Tea', 'product_price' => null]);
        $this->assertDatabaseHas('import_sales', ['snapshot_date' => '2026-11-07', 'name' => 'Tea', 'product_price' => '12.5000']);
        $this->assertSame(2, DB::table('import_sales')->count());
        Http::assertNothingSent();
    }

    public function test_one_tab_populates_two_structures_and_repeat_does_not_duplicate_snapshots(): void
    {
        $sales = $this->target();
        $products = SyncTarget::factory()->for($sales->tab, 'tab')->create(['target_table' => 'import_products']);
        $this->mapping($sales, 'name', 'product_name');
        $this->mapping($sales, 'price', 'product_price', 'decimal');
        $this->mapping($products, 'name', 'label');
        $this->data(['price', 'name'], [2 => ['price' => 12.5, 'name' => 'Tea'], 3 => ['price' => 12.5, 'name' => 'Tea']], [], 2);

        $this->assertSame([SyncRunStatus::Succeeded, SyncRunStatus::Succeeded], $this->importer()->importAll()->pluck('status')->all());
        $this->assertSame([SyncRunStatus::Skipped, SyncRunStatus::Skipped], $this->importer()->importAll()->pluck('status')->all());
        $this->assertSame(SyncRunStatus::Succeeded, $this->importer()->importTarget($sales)->status);

        $this->assertSame(2, DB::table('import_sales')->count());
        $this->assertSame(2, DB::table('import_products')->count());
        $this->assertDatabaseHas('import_sales', ['product_name' => 'Tea', 'product_price' => '12.5000']);
        $this->assertDatabaseHas('import_products', ['label' => 'Tea', 'sync_target_id' => $products->id]);
        $this->assertFalse(Schema::hasColumn('import_products', 'product_price'));
        $this->assertSame([2, 3], DB::table('import_sales')->orderBy('source_row')->pluck('source_row')->all());
        Http::assertNothingSent();
    }

    public function test_imports_cast_values_with_source_positions_and_snapshot_metadata(): void
    {
        $target = $this->target();
        $this->mapping($target, 'Name', 'name');
        $this->mapping($target, 'Price', 'price', 'decimal', false, ['decimal_separator' => ',', 'thousands_separator' => ' ']);
        $this->mapping($target, 'Date', 'sale_date', 'date', false, [], 'd.m.Y');
        $this->mapping($target, 'Active', 'active', 'boolean');
        $this->data(['name', 'price', 'date', 'active', 'extra'], [4 => ['name' => 'Tea', 'price' => '1 234,50', 'date' => '06.11.2026', 'active' => 'так', 'extra' => 'ignored'], 6 => ['name' => 'Tea', 'price' => 0, 'date' => '07.11.2026', 'active' => false]]);
        $run = $this->importer()->importTarget($target);
        $this->assertSame(SyncRunStatus::Succeeded, $run->status);
        $this->assertSame(2, $run->rows_processed);
        $this->assertNotNull($run->finished_at);
        $this->assertDatabaseHas('import_sales', ['source_row' => 4, 'price' => '1234.5000', 'sale_date' => '2026-11-06', 'active' => 1, 'snapshot_date' => '2026-11-07']);
        $this->assertDatabaseHas('import_sales', ['source_row' => 6, 'price' => '0.0000', 'active' => 0]);
        $this->assertFalse(Schema::hasColumn('import_sales', 'extra'));
        $this->assertSame(64, strlen(DB::table('import_sales')->value('row_hash')));
    }

    public function test_optional_missing_column_is_null_and_warns_once(): void
    {
        Log::spy();
        $target = $this->target();
        $this->mapping($target, 'Name', 'name');
        $this->mapping($target, 'Missing', 'notes', 'text', true);
        $this->data(['name'], [2 => ['name' => 'Tea'], 3 => ['name' => 'Coffee']]);
        $this->assertSame(SyncRunStatus::Succeeded, $this->importer()->importTarget($target)->status);
        $this->assertSame(2, DB::table('import_sales')->whereNull('notes')->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => ($context['target_id'] ?? null) === $target->id && ($context['source_column'] ?? null) === 'missing');
    }

    public function test_missing_required_or_ambiguous_selected_column_fails_only_its_target(): void
    {
        $first = $this->target();
        $this->mapping($first, 'total', 'total');
        $second = SyncTarget::factory()->for($first->tab, 'tab')->create(['target_table' => 'import_other']);
        $this->mapping($second, 'name', 'name');
        $this->data(['name', 'total', 'total'], [2 => ['name' => 'Tea']], ['total']);
        $runs = $this->importer()->importAll();
        $this->assertSame([SyncRunStatus::Failed, SyncRunStatus::Succeeded], $runs->pluck('status')->all());
        $this->assertFalse(Schema::hasTable('import_sales'));
        $this->assertDatabaseHas('import_other', ['name' => 'Tea']);
    }

    public function test_scheduled_retry_skips_but_manual_replaces_only_its_date(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->twice()->andReturn($this->dataset(['name'], [2 => ['name' => 'Old'], 3 => ['name' => 'Removed']]), $this->dataset(['name'], [4 => ['name' => 'New']]));
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->assertSame(SyncRunStatus::Succeeded, $this->importer()->importAll()->sole()->status);
        $this->assertSame(SyncRunStatus::Skipped, $this->importer()->importAll()->sole()->status);
        $this->assertSame(SyncRunStatus::Succeeded, $this->importer()->importTarget($target)->status);
        $this->assertSame(['New'], DB::table('import_sales')->pluck('name')->all());
        $this->assertSame(3, SyncRun::count());
    }

    public function test_empty_manual_snapshot_removes_old_rows_and_retains_other_dates(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $this->data(['name'], [2 => ['name' => 'History']], [], 1);
        $this->importer()->importTarget($target, '2026-11-06');
        $this->data(['name'], [], [], 1);
        $run = $this->importer()->importTarget($target);
        $this->assertSame(SyncRunStatus::Succeeded, $run->status);
        $this->assertSame(0, $run->rows_processed);
        $this->assertDatabaseHas('import_sales', ['snapshot_date' => '2026-11-06', 'name' => 'History']);
        $this->assertSame(0, DB::table('import_sales')->where('snapshot_date', '2026-11-07')->count());
    }

    public function test_bad_row_preserves_prior_snapshot_and_logs_safe_failure(): void
    {
        $target = $this->target();
        $this->mapping($target, 'amount', 'amount', 'integer');
        $this->data(['amount'], [2 => ['amount' => 7]], [], 1);
        $this->importer()->importTarget($target);
        $this->data(['amount'], [2 => ['amount' => 'SENSITIVE VALUE']], [], 1);
        $run = $this->importer()->importTarget($target);
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame(1, $run->rows_failed);
        $this->assertStringNotContainsString('SENSITIVE', $run->error);
        $this->assertDatabaseHas('import_sales', ['amount' => 7]);
    }

    public function test_optional_unique_keys_check_cast_values_and_default_allows_duplicates(): void
    {
        $target = $this->target();
        $this->mapping($target, 'amount', 'amount', 'integer');
        $this->data(['amount'], [2 => ['amount' => '01'], 3 => ['amount' => 1]], [], 2);
        $this->assertSame(SyncRunStatus::Succeeded, $this->importer()->importTarget($target)->status);
        $target->update(['unique_keys' => ['amount']]);
        $this->assertSame(SyncRunStatus::Failed, $this->importer()->importTarget($target)->status);
        $this->assertSame(2, DB::table('import_sales')->count());
    }

    public function test_invalid_unique_key_mapping_fails_before_ddl(): void
    {
        $target = $this->target();
        $target->update(['unique_keys' => ['missing']]);
        $this->mapping($target, 'name', 'name');
        $this->data(['name'], [2 => ['name' => 'Tea']]);
        $this->assertSame(SyncRunStatus::Failed, $this->importer()->importTarget($target)->status);
        $this->assertFalse(Schema::hasTable('import_sales'));
    }

    public function test_chunks_are_500_and_late_insert_failure_rolls_back_delete_and_previous_chunks(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $this->data(['name'], [2 => ['name' => 'Original']], [], 1);
        $this->importer()->importTarget($target);
        $rows = [];
        for ($row = 2; $row <= 1002; $row++) {
            $rows[$row] = ['name' => 'Tea'];
        }
        $this->data(['name'], $rows, [], 1);
        $inserts = 0;
        $counts = [];
        DB::listen(function (QueryExecuted $query) use (&$inserts, &$counts): void {
            if (str_starts_with($query->sql, 'insert into `import_sales`')) {
                $counts[] = count($query->bindings) / 7;
                if (++$inserts === 2) {
                    throw new \RuntimeException('SENSITIVE SQL FAILURE');
                }
            }
        });
        $run = $this->importer()->importTarget($target);
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame([500, 500], $counts);
        $this->assertSame(['Original'], DB::table('import_sales')->pluck('name')->all());
        $this->assertStringNotContainsString('SENSITIVE', $run->error);
    }

    public function test_inactive_hierarchy_is_excluded_and_failed_tab_does_not_stop_next(): void
    {
        $bad = $this->target();
        $this->mapping($bad, 'name', 'name');
        $good = $this->target('import_other');
        $this->mapping($good, 'name', 'name');
        $inactive = $this->target('import_inactive');
        $inactive->tab->source->update(['is_active' => false]);
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->with($bad->tab->source->spreadsheet_id, $bad->tab->tab_name, 1, Mockery::any())->once()->andThrow(new GoogleSheetsException('Access denied.', 403));
        $client->shouldReceive('getData')->with($good->tab->source->spreadsheet_id, $good->tab->tab_name, 1, Mockery::any())->once()->andReturn($this->dataset(['name'], [2 => ['name' => 'Tea']]));
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->assertSame([SyncRunStatus::Failed, SyncRunStatus::Succeeded], $this->importer()->importAll()->pluck('status')->all());
        $this->assertSame(2, SyncRun::count());
    }

    public function test_batch_date_is_frozen_in_kyiv_across_midnight(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(21, 59, 59));
        foreach (['import_sales', 'import_other'] as $table) {
            $this->mapping($this->target($table), 'name', 'name');
        }
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->twice()->andReturnUsing(function () {
            $this->travel(2)->seconds();

            return $this->dataset(['name'], [2 => ['name' => 'Tea']]);
        });
        $this->app->instance(GoogleSheetsClient::class, $client);
        $runs = $this->importer()->importAll();
        $this->assertSame(['2026-11-07', '2026-11-07'], $runs->map(fn ($run) => $run->snapshot_date->format('Y-m-d'))->all());
    }

    public function test_recovers_stale_attempt_under_lock_and_updates_heartbeat(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $stale = SyncRun::factory()->for($target, 'target')->create(['snapshot_date' => '2026-11-07', 'status' => SyncRunStatus::Running, 'started_at' => now()->subHour(), 'heartbeat_at' => now()->subHour(), 'finished_at' => null]);
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->once()->andReturnUsing(function ($id, $tab, $header, $heartbeat) {
            $this->travel(30)->seconds();
            $heartbeat();
            $this->assertTrue(SyncRun::query()->where('status', 'running')->sole()->heartbeat_at->equalTo(now()));

            return $this->dataset(['name'], [2 => ['name' => 'Tea']]);
        });
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->assertSame(SyncRunStatus::Succeeded, $this->importer()->importTarget($target)->status);
        $this->assertSame(SyncRunStatus::Failed, $stale->fresh()->status);
    }

    public function test_required_missing_header_fails_even_when_sheet_has_no_rows(): void
    {
        $target = $this->target();
        $this->mapping($target, 'required', 'name');
        $this->data(['other'], []);
        $this->assertSame(SyncRunStatus::Failed, $this->importer()->importTarget($target)->status);
        $this->assertFalse(Schema::hasTable('import_sales'));
    }

    public function test_successfully_inserts_all_three_chunks_and_duplicate_hashes_are_allowed(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $rows = [];
        for ($row = 2; $row <= 1002; $row++) {
            $rows[$row] = ['name' => 'Tea'];
        }
        $this->data(['name'], $rows);
        $run = $this->importer()->importTarget($target);
        $this->assertSame(SyncRunStatus::Succeeded, $run->status);
        $this->assertSame(1001, $run->rows_processed);
        $this->assertSame(1001, DB::table('import_sales')->count());
        $this->assertSame(1, DB::table('import_sales')->distinct()->count('row_hash'));
    }

    public function test_manual_replacement_keeps_rows_belonging_to_other_targets(): void
    {
        $first = $this->target();
        $second = $this->target();
        $this->mapping($first, 'name', 'name');
        $this->mapping($second, 'name', 'name');
        $this->data(['name'], [2 => ['name' => 'Tea']], [], 2);
        $this->importer()->importTarget($first);
        $this->importer()->importTarget($second);
        $this->data(['name'], []);
        $this->importer()->importTarget($first);
        $this->assertSame([$second->id], DB::table('import_sales')->pluck('sync_target_id')->all());
    }

    public function test_busy_snapshot_lock_skips_without_reading_google(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldNotReceive('getData');
        $this->app->instance(GoogleSheetsClient::class, $client);
        config(['database.connections.snapshot_other' => config('database.connections.mysql')]);
        $other = DB::connection('snapshot_other');
        $lock = 'bulls:snapshot:'.sha1(DB::getDatabaseName().chr(0).$target->id.chr(0).'2026-11-07');
        $other->selectOne('SELECT GET_LOCK(?,0)', [$lock]);
        try {
            $this->assertSame(SyncRunStatus::Skipped, $this->importer()->importTarget($target)->status);
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
            DB::disconnect('snapshot_other');
        }
    }

    public function test_lost_snapshot_session_does_not_replay_data_changes(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $this->data(['name'], [2 => ['name' => 'Original']], [], 1);
        $this->importer()->importTarget($target);
        $this->data(['name'], [2 => ['name' => 'New']], [], 0);
        $session = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        config(['database.connections.snapshot_killer' => config('database.connections.mysql')]);
        $other = DB::connection('snapshot_killer');
        $killed = false;
        DB::listen(function (QueryExecuted $query) use ($session, $other, &$killed): void {
            if (! $killed && str_contains($query->sql, 'GET_LOCK') && str_starts_with($query->bindings[0] ?? '', 'bulls:snapshot:')) {
                $killed = true;
                $other->statement('KILL CONNECTION '.$session);
            }
        });
        try {
            $run = $this->importer()->importTarget($target);
            $this->assertSame(SyncRunStatus::Failed, $run->status);
            $this->assertTrue($killed);
            $this->assertSame(['Original'], DB::table('import_sales')->pluck('name')->all());
        } finally {
            DB::disconnect('snapshot_killer');
        }
    }

    public function test_stale_pending_is_failed_and_lost_running_status_cannot_publish(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $pending = SyncRun::factory()->for($target, 'target')->create(['snapshot_date' => '2026-11-07', 'status' => SyncRunStatus::Pending, 'started_at' => null, 'heartbeat_at' => null, 'created_at' => now()->subHour()]);
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->once()->andReturnUsing(function () {
            DB::table('sync_runs')->where('status', 'running')->update(['status' => 'failed', 'error' => 'Externally expired', 'finished_at' => now()]);

            return $this->dataset(['name'], [2 => ['name' => 'Tea']]);
        });
        $this->app->instance(GoogleSheetsClient::class, $client);
        $run = $this->importer()->importTarget($target);
        $this->assertSame(SyncRunStatus::Failed, $pending->fresh()->status);
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertFalse(Schema::hasTable('import_sales'));
    }

    public function test_future_snapshot_date_is_rejected_without_creating_an_attempt(): void
    {
        $target = $this->target();
        $this->expectException(SheetImportException::class);
        try {
            $this->importer()->importTarget($target, '2026-11-08');
        } finally {
            $this->assertSame(0, SyncRun::count());
        }
    }

    public function test_failed_heartbeat_does_not_poison_sibling_targets_read_cache(): void
    {
        $first = $this->target();
        $this->mapping($first, 'name', 'name');
        $second = SyncTarget::factory()->for($first->tab, 'tab')->create(['target_table' => 'import_other']);
        $this->mapping($second, 'name', 'name');
        $calls = 0;
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->twice()->andReturnUsing(function ($id, $tab, $header, $heartbeat) use (&$calls) {
            if (++$calls === 1) {
                DB::table('sync_runs')->where('status', 'running')->update(['status' => 'failed', 'error' => 'Expired', 'finished_at' => now()]);
                $heartbeat();
            }

            return $this->dataset(['name'], [2 => ['name' => 'Tea']]);
        });
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->assertSame([SyncRunStatus::Failed, SyncRunStatus::Succeeded], $this->importer()->importAll()->pluck('status')->all());
        $this->assertDatabaseHas('import_other', ['name' => 'Tea']);
    }

    public function test_config_drift_during_read_does_not_replace_the_old_or_new_destination(): void
    {
        $target = $this->target();
        $this->mapping($target, 'name', 'name');
        $this->data(['name'], [2 => ['name' => 'Original']], [], 1);
        $this->importer()->importTarget($target);
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->once()->andReturnUsing(function () use ($target) {
            $target->update(['target_table' => 'import_new_destination']);

            return $this->dataset(['name'], [2 => ['name' => 'New']]);
        });
        $this->app->instance(GoogleSheetsClient::class, $client);
        $run = $this->importer()->importTarget($target);
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame(['Original'], DB::table('import_sales')->pluck('name')->all());
        $this->assertFalse(Schema::hasTable('import_new_destination'));
    }

    public function test_collects_bounded_cell_errors_but_counts_all_failed_rows(): void
    {
        $target = $this->target();
        $this->mapping($target, 'amount', 'amount', 'integer');
        $this->mapping($target, 'price', 'price', 'decimal', false, ['precision' => 12, 'scale' => 2]);
        $rows = [];
        for ($row = 2; $row <= 26; $row++) {
            $rows[$row] = ['amount' => 'PRIVATE_BAD_VALUE', 'price' => '12.345'];
        }
        $this->data(['amount', 'price'], $rows);
        $run = $this->importer()->importTarget($target);
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame(25, $run->rows_failed);
        $report = json_decode($run->error, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(20, $report['errors']);
        $this->assertSame(50, $report['total_errors']);
        $this->assertTrue($report['truncated']);
        $this->assertSame(['row', 'column', 'reason'], array_keys($report['errors'][0]));
        $this->assertSame(2, $report['errors'][0]['row']);
        $this->assertSame('amount', $report['errors'][0]['column']);
        $this->assertStringNotContainsString('PRIVATE_BAD_VALUE', $run->error);
        $this->assertStringNotContainsString('12.345', $run->error);
        $this->assertFalse(Schema::hasTable('import_sales'));
    }

    public function test_datetime_uses_mapping_timezone_and_date_keeps_calendar_day(): void
    {
        $target = $this->target();
        $this->mapping($target, 'when', 'occurred_at', 'datetime', false, ['timezone' => 'Europe/Kyiv']);
        $this->mapping($target, 'day', 'sale_date', 'date', false, ['timezone' => 'Europe/Kyiv']);
        $this->data(['when', 'day'], [2 => ['when' => '2024-01-02 00:30:00', 'day' => '2024-01-02']]);
        $this->assertSame(SyncRunStatus::Succeeded, $this->importer()->importTarget($target)->status);
        $this->assertDatabaseHas('import_sales', ['occurred_at' => '2024-01-01 22:30:00', 'sale_date' => '2024-01-02']);
    }

    #[DataProvider('permanentHttpStatuses')]
    public function test_permanent_http_failure_is_cached_for_sibling_targets(int $status): void
    {
        $first = $this->target();
        $second = SyncTarget::factory()->for($first->tab, 'tab')->create(['target_table' => 'import_other']);
        $this->mapping($first, 'name', 'name');
        $this->mapping($second, 'name', 'name');
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->once()->andThrow(new GoogleSheetsApiException('Permanent API failure.', $status, false));
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->assertSame([SyncRunStatus::Failed, SyncRunStatus::Failed], $this->importer()->importAll()->pluck('status')->all());
    }

    public static function permanentHttpStatuses(): array
    {
        return [[400], [403], [404]];
    }

    public function test_quota_403_is_not_cached_as_a_permanent_tab_failure(): void
    {
        $first = $this->target();
        $second = SyncTarget::factory()->for($first->tab, 'tab')->create(['target_table' => 'import_other']);
        $this->mapping($first, 'name', 'name');
        $this->mapping($second, 'name', 'name');
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->once()->ordered()->andThrow(new GoogleSheetsApiException('Quota exhausted.', 403, true));
        $client->shouldReceive('getData')->once()->ordered()->andReturn($this->dataset(['name'], [2 => ['name' => 'Tea']]));
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->assertSame([SyncRunStatus::Failed, SyncRunStatus::Succeeded], $this->importer()->importAll()->pluck('status')->all());
    }

    public function test_groups_targets_by_tab_before_reading_the_next_tab(): void
    {
        $first = $this->target();
        $other = $this->target('import_other');
        $sibling = SyncTarget::factory()->for($first->tab, 'tab')->create(['target_table' => 'import_sibling']);
        foreach ([$first, $other, $sibling] as $target) {
            $this->mapping($target, 'name', 'name');
        }
        $this->data(['name'], [2 => ['name' => 'Tea']], [], 2);
        $this->assertSame([$first->id, $sibling->id, $other->id], $this->importer()->importAll()->pluck('target_id')->all());
    }

    public function test_inactive_targets_do_not_create_automatic_skipped_records(): void
    {
        $target = $this->target();
        $target->update(['is_active' => false]);
        $this->data(['name'], [], [], 0);
        $this->assertCount(0, $this->importer()->importAll());
        $this->assertCount(0, $this->importer()->importAll());
        $this->assertSame(0, SyncRun::count());
        $this->assertSame(SyncRunStatus::Skipped, $this->importer()->importTarget($target)->status);
        $this->assertSame(1, SyncRun::count());
    }

    private function target(string $table = 'import_sales'): SyncTarget
    {
        return SyncTarget::factory()->create(['target_table' => $table]);
    }

    private function mapping(SyncTarget $target, string $source, string $column, string $type = 'string', bool $nullable = false, array $options = [], ?string $format = null): void
    {
        ColumnMapping::factory()->for($target, 'target')->create(['source_column' => $source, 'target_column' => $column, 'data_type' => $type, 'nullable' => $nullable, 'type_options' => $options ?: null, 'format' => $format]);
    }

    private function dataset(array $headers, array $rows, array $ambiguous = []): array
    {
        return compact('headers', 'rows', 'ambiguous');
    }

    private function data(array $headers, array $rows, array $ambiguous = [], int $times = 1): void
    {
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->times($times)->andReturn($this->dataset($headers, $rows, $ambiguous));
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->app->forgetInstance(SheetImporter::class);
    }

    private function importer(): SheetImporter
    {
        return app(SheetImporter::class);
    }
}
