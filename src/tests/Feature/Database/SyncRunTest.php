<?php

namespace Tests\Feature\Database;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunTriggerType;
use App\Models\ColumnMapping;
use App\Models\SheetSource;
use App\Models\SheetTab;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

class SyncRunTest extends DatabaseTestCase
{
    public function test_multiple_attempts_for_one_date_preserve_casts_and_defaults(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $target = SyncTarget::factory()->create();
        $run = $target->runs()->create([
            'snapshot_date' => '2026-11-08', 'trigger_type' => SyncRunTriggerType::Manual,
            'status' => SyncRunStatus::Failed,
        ])->fresh();
        $second = SyncRun::factory()->for($target, 'target')->create([
            'snapshot_date' => '2026-11-08', 'status' => SyncRunStatus::Succeeded,
            'started_at' => now(), 'heartbeat_at' => now(), 'finished_at' => now(),
        ])->fresh();
        $this->assertCount(2, $target->runs);
        $this->assertTrue($run->target->is($target));
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame(SyncRunTriggerType::Manual, $run->trigger_type);
        $this->assertSame('2026-11-08', $run->snapshot_date->format('Y-m-d'));
        foreach (['rows_processed', 'rows_skipped', 'rows_failed'] as $counter) {
            $this->assertSame(0, $run->{$counter});
        }
        foreach (['error', 'started_at', 'heartbeat_at', 'finished_at'] as $field) {
            $this->assertNull($run->{$field});
        }
        $this->assertInstanceOf(CarbonImmutable::class, $second->started_at);
        $this->assertInstanceOf(CarbonImmutable::class, $second->heartbeat_at);
        $this->assertInstanceOf(CarbonImmutable::class, $second->finished_at);
    }

    public function test_snapshot_ui_counters_are_computed_without_redundant_storage(): void
    {
        $run = SyncRun::factory()->create(['rows_processed' => 7])->fresh();
        $this->assertSame(7, $run->rows_inserted);
        $this->assertSame(0, $run->rows_updated);
        $this->assertSame(7, $run->toArray()['rows_inserted']);
        $this->assertSame(0, $run->toArray()['rows_updated']);
    }

    public function test_target_with_any_run_cannot_be_deleted(): void
    {
        $mapping = ColumnMapping::factory()->create();
        $run = SyncRun::factory()->for($mapping->target, 'target')->create();
        try {
            $mapping->target->delete();
            $this->fail('A target with a run must not be deleted.');
        } catch (QueryException $exception) {
            $this->assertSame(1451, $exception->errorInfo[1]);
        }
        $this->assertModelExists($mapping);
        $this->assertModelExists($mapping->target);
        $this->assertModelExists($run);
    }

    #[DataProvider('runStatuses')]
    public function test_one_logged_branch_prevents_the_entire_cascade(string $status): void
    {
        $source = SheetSource::factory()->create();
        $cleanTab = SheetTab::factory()->for($source, 'source')->create();
        $loggedTab = SheetTab::factory()->for($source, 'source')->create();
        $cleanTarget = SyncTarget::factory()->for($cleanTab, 'tab')->create();
        $loggedTarget = SyncTarget::factory()->for($loggedTab, 'tab')->create();
        $cleanMapping = ColumnMapping::factory()->for($cleanTarget, 'target')->create();
        $loggedMapping = ColumnMapping::factory()->for($loggedTarget, 'target')->create();
        $run = SyncRun::factory()->for($loggedTarget, 'target')->create(['status' => $status]);
        try {
            $source->delete();
            $this->fail('A source with a logged target must not be deleted.');
        } catch (QueryException $exception) {
            $this->assertSame(1451, $exception->errorInfo[1]);
        }
        foreach ([$source, $cleanTab, $loggedTab, $cleanTarget, $loggedTarget, $cleanMapping, $loggedMapping, $run] as $model) {
            $this->assertModelExists($model);
        }
    }

    /** @return array<string, array{string}> */
    public static function runStatuses(): array
    {
        return [
            'pending' => ['pending'], 'running' => ['running'], 'succeeded' => ['succeeded'],
            'failed' => ['failed'], 'skipped' => ['skipped'],
        ];
    }
}
