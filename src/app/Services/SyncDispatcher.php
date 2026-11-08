<?php

namespace App\Services;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunTriggerType;
use App\Exceptions\SheetImportException;
use App\Jobs\SyncTabJob;
use App\Models\SheetSource;
use App\Models\SheetTab;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use App\Services\Importing\ConfigurationFingerprint;
use App\Services\Importing\SchedulingPolicy;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class SyncDispatcher
{
    public function __construct(private readonly ConfigurationFingerprint $fingerprints, private readonly SchedulingPolicy $policy) {}

    public function enqueue(?int $source = null, ?int $tab = null, ?string $date = null, SyncRunTriggerType $trigger = SyncRunTriggerType::Manual): int
    {
        $this->assertRuntimeConfiguration();
        $date = app(SheetImporter::class)->snapshotDate($date, $trigger);
        if (($source !== null && ! SheetSource::whereKey($source)->exists()) || ($tab !== null && ! SheetTab::whereKey($tab)->when($source !== null, fn ($query) => $query->where('source_id', $source))->exists())) {
            throw new SheetImportException('Source or tab filter does not match an existing configuration.');
        }
        $targets = SyncTarget::query()->where('is_active', true)->when($tab !== null, fn ($query) => $query->where('tab_id', $tab))
            ->whereHas('tab', fn ($query) => $query->where('is_active', true)->when($source !== null, fn ($query) => $query->where('source_id', $source))->whereHas('source', fn ($query) => $query->where('is_active', true)))
            ->orderBy('tab_id')->orderBy('id')->get()->groupBy('tab_id');
        $count = 0;
        foreach ($targets as $tabId => $group) {
            $dispatchId = (string) Str::uuid();
            $runs = [];
            try {
                foreach ($group as $target) {
                    foreach (SyncRun::where('target_id', $target->id)->whereIn('status', ['pending', 'running'])->get() as $abandoned) {
                        $this->recoverAbandoned($abandoned);
                    }
                    if ($run = $this->reserve($target, $date, $trigger, $dispatchId)) {
                        $runs[] = $run;
                    }
                }
                if ($runs !== []) {
                    Queue::connection('database')->push(new SyncTabJob($dispatchId, $tabId, $date, $trigger->value), '', 'sheets');
                    $count++;
                }
            } catch (Throwable) {
                SyncRun::whereIn('id', array_map(fn ($run) => $run->id, $runs))->where('status', 'pending')->update(['status' => 'failed', 'failure_kind' => 'internal', 'error' => 'Unable to enqueue tab import.', 'finished_at' => now()]);
                throw new SheetImportException('Unable to enqueue tab import. Reserved attempts were closed; retry the command.');
            }
        }

        return $count;
    }

    /** @return list<int> */
    public function pending(string $dispatchId, int $tabId, string $date, SyncRunTriggerType $trigger): array
    {
        $runs = SyncRun::where('dispatch_id', $dispatchId)->where('snapshot_date', $date)->where('trigger_type', $trigger->value)
            ->whereHas('target', fn ($query) => $query->where('tab_id', $tabId))->orderByDesc('id')->get()->unique('target_id');
        foreach ($runs as $run) {
            if ($run->status === SyncRunStatus::Running) {
                $this->recoverAbandoned($run);
            }
        }
        $runs = $runs->map(fn ($run) => $run->fresh());
        if ($trigger === SyncRunTriggerType::Scheduled && $date !== today('Europe/Kyiv')->toDateString()) {
            SyncRun::whereIn('id', $runs->pluck('id'))->where('status', 'pending')->update(['status' => 'skipped', 'error' => 'Scheduled delivery expired at Kyiv midnight.', 'finished_at' => now()]);

            return [];
        }
        $ids = [];
        foreach ($runs as $run) {
            if ($run->status === SyncRunStatus::Pending) {
                $ids[] = $run->id;
            } elseif ($run->status === SyncRunStatus::Failed && $run->failure_kind === 'schema_lock_timeout'
                && SyncRun::where('dispatch_id', $dispatchId)->where('target_id', $run->target_id)->count() < 3) {
                if ($retry = $this->reserve($run->target, $date, $trigger, $dispatchId, $run->id)) {
                    $ids[] = $retry->id;
                }
            }
        }

        return $ids;
    }

    private function reserve(SyncTarget $target, string $date, SyncRunTriggerType $trigger, string $dispatchId, ?int $retryOf = null): ?SyncRun
    {
        return $this->withSnapshotLock($target, $date, function (Connection $connection) use ($target, $date, $trigger, $dispatchId, $retryOf): ?SyncRun {
            return $connection->transaction(function () use ($connection, $target, $date, $trigger, $dispatchId, $retryOf): ?SyncRun {
                $scope = fn () => $connection->table('sync_runs')->where('target_id', $target->id)->where('snapshot_date', $date);
                $scope()->whereIn('status', ['pending', 'running'])->whereRaw('COALESCE(heartbeat_at, started_at, created_at) < ?', [now()->subMinutes(config('sheet-import.stale_after_minutes'))->format('Y-m-d H:i:s')])
                    ->update(['status' => 'failed', 'failure_kind' => 'expired', 'error' => 'Import attempt expired due to inactivity.', 'finished_at' => now(), 'updated_at' => now()]);
                $target = $target->fresh(['tab.source']);
                if (! $target || ! $target->is_active || ! $target->tab->is_active || ! $target->tab->source->is_active || $scope()->whereIn('status', ['pending', 'running'])->exists()) {
                    return null;
                }
                if ($trigger === SyncRunTriggerType::Scheduled && ! $this->policy->eligible($connection, $target, $date)) {
                    return null;
                }
                if ($retryOf !== null && (int) $connection->table('sync_runs')->where('dispatch_id', $dispatchId)->where('target_id', $target->id)->max('id') !== $retryOf) {
                    return null;
                }
                $id = $connection->table('sync_runs')->insertGetId(['target_id' => $target->id, 'snapshot_date' => $date, 'trigger_type' => $trigger->value, 'status' => 'pending', 'config_fingerprint' => $this->fingerprints->read($connection, $target->id), 'dispatch_id' => $dispatchId, 'created_at' => now(), 'updated_at' => now()]);

                return SyncRun::findOrFail($id);
            });
        });
    }

    public function assertRuntimeConfiguration(): void
    {
        $retryAfter = config('queue.connections.database.retry_after');
        if (! is_int($retryAfter) || $retryAfter <= 630 || config('queue.connections.database.driver') !== 'database') {
            throw new SheetImportException('The database queue retry_after must exceed the 630-second external worker limit.');
        }
    }

    public function closeDispatch(string $dispatchId): void
    {
        foreach (SyncRun::where('dispatch_id', $dispatchId)->whereIn('status', ['pending', 'running'])->get() as $run) {
            $this->withSnapshotLock($run->target, $run->snapshot_date->format('Y-m-d'), function (Connection $connection) use ($run): void {
                $connection->table('sync_runs')->where('id', $run->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'failure_kind' => 'internal', 'error' => 'Queue worker stopped before the tab import completed.', 'finished_at' => now(), 'updated_at' => now()]);
            });
        }
    }

    private function recoverAbandoned(SyncRun $run): void
    {
        if ($run->status !== SyncRunStatus::Running && ! ($run->status === SyncRunStatus::Pending && ($run->heartbeat_at ?? $run->started_at ?? $run->created_at)->lte(now()->subMinutes(config('sheet-import.stale_after_minutes'))))) {
            return;
        }
        $this->withSnapshotLock($run->target, $run->snapshot_date->format('Y-m-d'), function (Connection $connection) use ($run): void {
            $connection->table('sync_runs')->where('id', $run->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'failure_kind' => 'expired', 'error' => 'Import attempt abandoned without its snapshot lock or expired in the queue.', 'finished_at' => now(), 'updated_at' => now()]);
        });
    }

    private function withSnapshotLock(SyncTarget $target, string $date, callable $callback): mixed
    {
        $main = $target->getConnection();
        if ($main->getDriverName() !== 'mysql' || $main->getTablePrefix() !== '' || $main->transactionLevel() !== 0 || $main->getPdo()->inTransaction()) {
            throw new SheetImportException('Dispatch requires MySQL outside transactions.');
        }
        $pdo = $main->getPdo();
        $connection = new MySqlConnection($pdo, $main->getDatabaseName(), '', $main->getConfig());
        $connection->setReadPdo($pdo);
        $connection->setReconnector(static function (): never {
            throw new SheetImportException('Dispatch session was lost.');
        });
        $lock = 'bulls:snapshot:'.sha1($main->getDatabaseName()."\0".$target->id."\0".$date);
        if ($connection->selectOne('SELECT IS_USED_LOCK(?) AS owner', [$lock], false)->owner !== null) {
            return null;
        }
        $acquired = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock], false)->acquired;
        if ((int) $acquired !== 1) {
            return null;
        }
        try {
            return $callback($connection);
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false);
        }
    }
}
