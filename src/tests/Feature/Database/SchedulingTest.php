<?php

namespace Tests\Feature\Database;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunTriggerType;
use App\Exceptions\GoogleSheetsApiException;
use App\Exceptions\ImportConfigurationException;
use App\Exceptions\RowValidationException;
use App\Exceptions\SchemaLockTimeoutException;
use App\Exceptions\SchemaManagerException;
use App\Exceptions\SheetImportException;
use App\Jobs\SyncTabJob;
use App\Models\ColumnMapping;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\Importing\ConfigurationFingerprint;
use App\Services\Importing\FailureClassifier;
use App\Services\Importing\SchedulingPolicy;
use App\Services\SchemaManager;
use App\Services\SheetImporter;
use App\Services\SyncDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\DdlDatabaseTestCase;

class SchedulingTest extends DdlDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(12, 0));
    }

    public function test_metadata_is_nullable_and_persisted_without_changing_old_runs(): void
    {
        $this->assertTrue(Schema::hasColumns('sync_runs', ['failure_kind', 'config_fingerprint', 'dispatch_id']));
        $run = SyncRun::factory()->create();
        $this->assertNull($run->fresh()->failure_kind);
        $run->update(['failure_kind' => 'configuration', 'config_fingerprint' => str_repeat('a', 64), 'dispatch_id' => 'dispatch-one']);
        $this->assertSame('configuration', $run->fresh()->failure_kind);
        $this->assertSame(str_repeat('a', 64), $run->fresh()->config_fingerprint);
    }

    public function test_fingerprint_ignores_timestamps_and_json_key_order_but_tracks_settings(): void
    {
        $target = $this->target();
        $mapping = $target->mappings()->first();
        $mapping->update(['type_options' => ['precision' => 12, 'scale' => 2]]);
        $before = $this->fingerprint($target);
        $target->update(['updated_at' => now()->addMinute()]);
        $mapping->update(['type_options' => ['scale' => 2, 'precision' => 12]]);
        $this->assertSame($before, $this->fingerprint($target));
        $mapping->update(['target_column' => 'renamed']);
        $this->assertNotSame($before, $this->fingerprint($target));
    }

    public function test_daily_budget_counts_only_scheduled_failures_and_survives_config_edits(): void
    {
        $target = $this->target();
        $this->attempt($target, 'failed', 'row_validation');
        $this->attempt($target, 'failed', 'transient');
        $this->attempt($target, 'failed', null, SyncRunTriggerType::Manual);
        $this->attempt($target, 'skipped');
        $this->assertTrue($this->eligible($target));
        $this->attempt($target, 'failed', 'internal');
        $target->update(['target_table' => 'schedule_changed']);
        $this->assertFalse($this->eligible($target));
        $this->travel(1)->days();
        $this->assertTrue($this->eligible($target));
    }

    public function test_permanent_block_survives_midnight_but_not_config_change_or_later_success(): void
    {
        $target = $this->target();
        $this->attempt($target, 'failed', 'api_permanent');
        $this->assertFalse($this->eligible($target));
        $this->travel(1)->days();
        $this->assertFalse($this->eligible($target));
        $target->update(['target_table' => 'schedule_changed']);
        $this->assertTrue($this->eligible($target));
        $this->attempt($target, 'failed', 'configuration');
        $this->attempt($target, 'failed', 'row_validation', SyncRunTriggerType::Manual);
        $this->assertFalse($this->eligible($target));
        $this->travel(1)->seconds();
        $this->attempt($target, 'succeeded', null, SyncRunTriggerType::Manual, '2026-11-06');
        $this->assertTrue($this->eligible($target));
        $this->travel(1)->seconds();
        $this->attempt($target, 'failed', 'configuration');
        $this->assertFalse($this->eligible($target));
    }

    public function test_success_active_parents_and_pending_work_filter_without_new_journal_rows(): void
    {
        $target = $this->target();
        $pending = $this->attempt($target, 'pending');
        $this->assertFalse($this->eligible($target));
        $this->assertTrue(app(SchedulingPolicy::class)->eligible(DB::connection(), $target, today('Europe/Kyiv')->toDateString(), $pending->id));
        $pending->update(['status' => 'succeeded', 'trigger_type' => 'manual']);
        $this->assertFalse($this->eligible($target));
        $this->travel(1)->days();
        $target->tab->source->update(['is_active' => false]);
        $this->assertFalse($this->eligible($target));
        $this->assertSame(1, SyncRun::count());
    }

    public function test_failure_classification_uses_types_and_quota_flags(): void
    {
        $classifier = app(FailureClassifier::class);
        $this->assertSame('configuration', $classifier->classify(new ImportConfigurationException('safe')));
        $this->assertSame('schema_lock_timeout', $classifier->classify(new SchemaLockTimeoutException('safe')));
        $this->assertSame('row_validation', $classifier->classify(new RowValidationException(1, [], 1)));
        $this->assertSame('api_permanent', $classifier->classify(new GoogleSheetsApiException('same message', 403, false)));
        $this->assertSame('transient', $classifier->classify(new GoogleSheetsApiException('same message', 403, true)));
        $this->assertSame('transient', $classifier->classify(new SchemaManagerException('Schema session lost.', retryable: true)));
        $this->assertSame('internal', $classifier->classify(new \RuntimeException('403 permission')));
    }

    public function test_dispatch_reserves_one_job_per_tab_and_deduplicates_pending_targets(): void
    {
        Queue::fake();
        $first = $this->target();
        $second = SyncTarget::factory()->for($first->tab, 'tab')->create(['target_table' => 'schedule_other']);
        ColumnMapping::factory()->for($second, 'target')->create(['source_column' => 'name', 'target_column' => 'name', 'data_type' => 'string']);
        $dispatcher = app(SyncDispatcher::class);
        $this->assertSame(1, $dispatcher->enqueue(trigger: SyncRunTriggerType::Scheduled));
        $this->assertSame(0, $dispatcher->enqueue(trigger: SyncRunTriggerType::Scheduled));
        Queue::assertPushed(SyncTabJob::class, 1);
        $this->assertSame(2, SyncRun::where('status', 'pending')->count());
        $this->assertSame(1, SyncRun::distinct()->count('dispatch_id'));
        $this->google(1);
        $job = Queue::pushed(SyncTabJob::class)->first();
        $job->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $job->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame(2, SyncRun::count());
        $this->assertSame(2, SyncRun::where('status', 'succeeded')->count());
        $this->assertDatabaseHas('schedule_sales', ['name' => 'Tea']);
        $this->assertDatabaseHas('schedule_other', ['name' => 'Tea']);
    }

    public function test_manual_dispatch_bypasses_limits_without_erasing_counts(): void
    {
        Queue::fake();
        $target = $this->target();
        for ($i = 0; $i < 3; $i++) {
            $this->attempt($target, 'failed', 'configuration');
        }
        $this->assertSame(0, app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled));
        $this->assertSame(1, app(SyncDispatcher::class)->enqueue());
        $this->google(1);
        Queue::pushed(SyncTabJob::class)->first()->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame(3, SyncRun::where('status', 'failed')->where('trigger_type', 'scheduled')->count());
        $this->assertSame(1, SyncRun::where('status', 'succeeded')->where('trigger_type', 'manual')->count());
    }

    public function test_stale_pending_is_recovered_and_late_delivery_is_fenced(): void
    {
        Queue::fake();
        $target = $this->target();
        app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled);
        $oldJob = Queue::pushed(SyncTabJob::class)->first();
        $old = SyncRun::sole();
        $this->travel(16)->minutes();
        app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled);
        $this->assertSame('expired', $old->fresh()->failure_kind);
        $this->google(0);
        $oldJob->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame(1, SyncRun::where('status', 'pending')->count());
        $this->assertSame(2, SyncRun::count());
    }

    public function test_old_day_job_expires_without_api_calls_or_historical_backfill(): void
    {
        Queue::fake();
        $this->target();
        app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled);
        $this->travelTo(now('Europe/Kyiv')->addDay()->startOfDay());
        $this->google(0);
        Queue::pushed(SyncTabJob::class)->first()->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame(SyncRunStatus::Skipped, SyncRun::sole()->status);
        $this->assertFalse(Schema::hasTable('schedule_sales'));
    }

    public function test_configuration_change_after_reservation_fails_before_reading(): void
    {
        Queue::fake();
        $target = $this->target();
        app(SyncDispatcher::class)->enqueue();
        $target->mappings()->update(['target_column' => 'changed']);
        $this->google(0);
        Queue::pushed(SyncTabJob::class)->first()->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame(SyncRunStatus::Failed, SyncRun::sole()->status);
        $this->assertFalse(Schema::hasTable('schedule_sales'));
    }

    public function test_schema_lock_retry_only_retries_failed_sibling_and_obeys_daily_cap(): void
    {
        Queue::fake();
        $first = $this->target();
        $second = SyncTarget::factory()->for($first->tab, 'tab')->create(['target_table' => 'schedule_other']);
        ColumnMapping::factory()->for($second, 'target')->create(['source_column' => 'name', 'target_column' => 'name', 'data_type' => 'string']);
        app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled);
        $realSchema = app(SchemaManager::class);
        $schema = Mockery::mock(SchemaManager::class);
        $schema->shouldReceive('ensure')->withArgs(fn ($target) => $target->id === $first->id)->once()->andReturnUsing(fn ($target) => $realSchema->ensure($target));
        $schema->shouldReceive('ensure')->withArgs(fn ($target) => $target->id === $second->id)->times(3)->andThrow(new SchemaLockTimeoutException('Schema lock busy.'));
        $this->app->instance(SchemaManager::class, $schema);
        $this->google(3);
        $job = Queue::pushed(SyncTabJob::class)->first();
        for ($i = 0; $i < 3; $i++) {
            $job->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        }
        $this->assertSame(1, SyncRun::where('target_id', $first->id)->count());
        $this->assertSame(3, SyncRun::where('target_id', $second->id)->where('failure_kind', 'schema_lock_timeout')->count());
        $this->assertSame(0, app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled));
    }

    public function test_enqueue_failure_finishes_reserved_attempts_with_safe_error(): void
    {
        $this->target();
        Queue::shouldReceive('connection')->with('database')->once()->andThrow(new \RuntimeException('PRIVATE_DATABASE_DETAILS'));
        try {
            app(SyncDispatcher::class)->enqueue();
            $this->fail('Expected dispatch failure.');
        } catch (SheetImportException $exception) {
            $this->assertStringNotContainsString('PRIVATE_DATABASE_DETAILS', $exception->getMessage());
        }
        $this->assertSame(SyncRunStatus::Failed, SyncRun::sole()->status);
        $this->assertSame('internal', SyncRun::sole()->failure_kind);
    }

    public function test_database_job_backoff_releases_reservation_until_available_at(): void
    {
        $this->target();
        app(SyncDispatcher::class)->enqueue();
        $queue = Queue::connection('database');
        $job = $queue->pop('sheets');
        $this->assertNotNull($job);
        $job->release(60);
        $record = DB::table('jobs')->sole();
        $this->assertNull($record->reserved_at);
        $this->assertSame(now()->addSeconds(60)->timestamp, $record->available_at);
        $this->assertNull($queue->pop('sheets'));
        $this->travel(60)->seconds();
        $this->assertNotNull($queue->pop('sheets'));
    }

    public function test_busy_independent_session_prevents_reservation_without_journal_noise(): void
    {
        Queue::fake();
        $target = $this->target();
        config(['database.connections.scheduling_other' => config('database.connections.mysql')]);
        $other = DB::connection('scheduling_other');
        $lock = 'bulls:snapshot:'.sha1(DB::getDatabaseName().chr(0).$target->id.chr(0).'2026-11-07');
        $other->selectOne('SELECT GET_LOCK(?, 0)', [$lock]);
        try {
            $this->assertSame(0, app(SyncDispatcher::class)->enqueue());
            $this->assertSame(0, SyncRun::count());
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
            DB::disconnect('scheduling_other');
        }
    }

    public function test_duplicate_delivery_cannot_skip_a_run_owned_by_another_worker(): void
    {
        Queue::fake();
        $target = $this->target();
        app(SyncDispatcher::class)->enqueue();
        $run = SyncRun::sole();
        config(['database.connections.scheduling_other' => config('database.connections.mysql')]);
        $other = DB::connection('scheduling_other');
        $lock = 'bulls:snapshot:'.sha1(DB::getDatabaseName().chr(0).$target->id.chr(0).'2026-11-07');
        $other->selectOne('SELECT GET_LOCK(?, 0)', [$lock]);
        $this->google(0);
        try {
            $results = app(SheetImporter::class)->importPending([$run->id]);
            $this->assertSame(SyncRunStatus::Pending, $run->fresh()->status);
            $this->assertSame(SyncRunStatus::Pending, $results->sole()->status);
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
            DB::disconnect('scheduling_other');
        }
    }

    public function test_aged_pending_delivery_is_expired_even_before_another_dispatch(): void
    {
        Queue::fake();
        $this->target();
        app(SyncDispatcher::class)->enqueue();
        $this->travel(16)->minutes();
        $this->google(0);
        Queue::pushed(SyncTabJob::class)->first()->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame(SyncRunStatus::Failed, SyncRun::sole()->status);
        $this->assertSame('expired', SyncRun::sole()->failure_kind);
    }

    public function test_permanent_api_failure_is_classified_and_suppresses_future_dispatch(): void
    {
        Queue::fake();
        $this->target();
        app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled);
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->once()->andThrow(new GoogleSheetsApiException('Permission denied.', 403, false));
        $this->app->instance(GoogleSheetsClient::class, $client);
        Queue::pushed(SyncTabJob::class)->first()->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame('api_permanent', SyncRun::sole()->failure_kind);
        $this->travel(1)->days();
        $this->assertSame(0, app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled));
        $this->assertSame(1, SyncRun::count());
    }

    public function test_inactive_delivery_and_budget_exhaustion_do_not_read_google(): void
    {
        Queue::fake();
        $target = $this->target();
        app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled);
        for ($i = 0; $i < 3; $i++) {
            $this->attempt($target, 'failed', 'row_validation');
        }
        $this->google(0);
        Queue::pushed(SyncTabJob::class)->first()->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame(1, SyncRun::where('status', 'skipped')->count());
        $target->tab->source->update(['is_active' => false]);
        $this->assertSame(0, app(SyncDispatcher::class)->enqueue());
    }

    public function test_command_filters_reserve_only_the_selected_tab_and_invalid_date_is_rejected(): void
    {
        Queue::fake();
        $first = $this->target();
        $other = $this->target('schedule_other');
        $this->artisan('sheets:sync --source='.$first->tab->source_id.' --tab='.$first->tab_id)->assertSuccessful();
        $this->assertSame([$first->id], SyncRun::pluck('target_id')->all());
        $this->artisan('sheets:sync --source='.$first->tab->source_id.' --tab='.$other->tab_id)->assertFailed();
        $this->artisan('sheets:sync --date=2099-01-01')->assertFailed();
        $this->artisan('sheets:sync --scheduled --date=2026-11-06')->assertFailed();
        Queue::assertPushed(SyncTabJob::class, 1);
    }

    public function test_abandoned_running_delivery_is_closed_without_replaying_data_even_after_midnight(): void
    {
        Queue::fake();
        $this->target();
        app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled);
        $run = SyncRun::sole();
        $run->update(['status' => 'running', 'heartbeat_at' => now()]);
        $this->travelTo(now('Europe/Kyiv')->addDay()->startOfDay());
        $this->google(0);
        Queue::pushed(SyncTabJob::class)->first()->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame(SyncRunStatus::Failed, $run->fresh()->status);
        $this->assertSame('expired', $run->fresh()->failure_kind);
        $this->assertFalse(Schema::hasTable('schedule_sales'));
    }

    public function test_live_running_delivery_keeps_its_owner_and_is_not_recovered(): void
    {
        Queue::fake();
        $target = $this->target();
        app(SyncDispatcher::class)->enqueue();
        $run = SyncRun::sole();
        $run->update(['status' => 'running', 'heartbeat_at' => now()->subHour()]);
        config(['database.connections.scheduling_other' => config('database.connections.mysql')]);
        $other = DB::connection('scheduling_other');
        $lock = 'bulls:snapshot:'.sha1(DB::getDatabaseName().chr(0).$target->id.chr(0).'2026-11-07');
        $other->selectOne('SELECT GET_LOCK(?, 0)', [$lock]);
        $this->google(0);
        try {
            Queue::pushed(SyncTabJob::class)->first()->handle(app(SyncDispatcher::class), app(SheetImporter::class));
            $this->assertSame(SyncRunStatus::Running, $run->fresh()->status);
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
            DB::disconnect('scheduling_other');
        }
    }

    public function test_deactivated_delivery_skips_without_blocking_later_reactivation(): void
    {
        Queue::fake();
        $target = $this->target();
        app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled);
        $target->tab->update(['is_active' => false]);
        $this->google(0);
        Queue::pushed(SyncTabJob::class)->first()->handle(app(SyncDispatcher::class), app(SheetImporter::class));
        $this->assertSame(SyncRunStatus::Skipped, SyncRun::sole()->status);
        $target->tab->update(['is_active' => true]);
        $this->assertTrue($this->eligible($target));
    }

    public function test_invalid_worker_retry_after_is_rejected_before_reserving_work(): void
    {
        Queue::fake();
        $this->target();
        config(['queue.connections.database.retry_after' => 90]);
        $this->artisan('sheets:sync')->assertFailed();
        $this->assertSame(0, SyncRun::count());
        Queue::assertNothingPushed();
    }

    public function test_serialized_database_job_releases_schema_retry_and_preserves_sibling_success(): void
    {
        $first = $this->target();
        $second = SyncTarget::factory()->for($first->tab, 'tab')->create(['target_table' => 'schedule_other']);
        ColumnMapping::factory()->for($second, 'target')->create(['source_column' => 'name', 'target_column' => 'name', 'data_type' => 'string']);
        app(SyncDispatcher::class)->enqueue(trigger: SyncRunTriggerType::Scheduled);
        $real = app(SchemaManager::class);
        $schema = Mockery::mock(SchemaManager::class);
        $schema->shouldReceive('ensure')->once()->withArgs(fn ($target) => $target->id === $first->id)->andReturnUsing(fn ($target) => $real->ensure($target));
        $schema->shouldReceive('ensure')->once()->withArgs(fn ($target) => $target->id === $second->id)->ordered()->andThrow(new SchemaLockTimeoutException('Busy schema.'));
        $schema->shouldReceive('ensure')->once()->withArgs(fn ($target) => $target->id === $second->id)->ordered()->andReturnUsing(fn ($target) => $real->ensure($target));
        $this->app->instance(SchemaManager::class, $schema);
        $this->google(2);
        $queue = Queue::connection('database');
        $job = $queue->pop('sheets');
        $job->fire();
        $this->assertNull(DB::table('jobs')->sole()->reserved_at);
        $this->assertNull($queue->pop('sheets'));
        $this->travel(60)->seconds();
        $retry = $queue->pop('sheets');
        $this->assertSame(2, $retry->attempts());
        $retry->fire();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(2, SyncRun::where('status', 'succeeded')->count());
        $this->assertSame(1, SyncRun::where('target_id', $first->id)->count());
        $this->assertSame(2, SyncRun::where('target_id', $second->id)->count());
    }

    private function google(int $times): void
    {
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getData')->times($times)->andReturn(['headers' => ['name'], 'ambiguous' => [], 'rows' => [4 => ['name' => 'Tea']]]);
        $this->app->instance(GoogleSheetsClient::class, $client);
    }

    private function target(string $table = 'schedule_sales'): SyncTarget
    {
        $target = SyncTarget::factory()->create(['target_table' => $table]);
        ColumnMapping::factory()->for($target, 'target')->create(['source_column' => 'name', 'target_column' => 'name', 'data_type' => 'string', 'nullable' => false]);

        return $target;
    }

    private function fingerprint(SyncTarget $target): string
    {
        return app(ConfigurationFingerprint::class)->read(DB::connection(), $target->id);
    }

    private function eligible(SyncTarget $target): bool
    {
        return app(SchedulingPolicy::class)->eligible(DB::connection(), $target, today('Europe/Kyiv')->toDateString());
    }

    private function attempt(SyncTarget $target, string $status, ?string $kind = null, SyncRunTriggerType $trigger = SyncRunTriggerType::Scheduled, ?string $date = null): SyncRun
    {
        return SyncRun::factory()->for($target, 'target')->create(['status' => $status, 'trigger_type' => $trigger, 'snapshot_date' => $date ?? today('Europe/Kyiv')->toDateString(), 'failure_kind' => $kind, 'config_fingerprint' => $this->fingerprint($target), 'finished_at' => in_array($status, ['failed', 'succeeded', 'skipped']) ? now() : null]);
    }
}
