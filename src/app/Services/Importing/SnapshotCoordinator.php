<?php

namespace App\Services\Importing;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunTriggerType;
use App\Exceptions\GoogleSheetsException;
use App\Exceptions\RowValidationException;
use App\Exceptions\SchemaManagerException;
use App\Exceptions\SheetImportException;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SnapshotCoordinator
{
    /** @param callable(Connection, callable, SyncRun): int $import */
    public function execute(SyncTarget $target, string $date, SyncRunTriggerType $trigger, callable $import): SyncRun
    {
        $main = $target->getConnection();
        if ($main->getDriverName() !== 'mysql' || $main->getTablePrefix() !== '' || $main->transactionLevel() !== 0 || $main->getPdo()->inTransaction()) {
            throw new SheetImportException('Importer requires MySQL without a prefix and must run outside transactions.');
        }
        $timeout = config('sheet-import.lock_timeout_seconds');
        $staleMinutes = config('sheet-import.stale_after_minutes');
        if (! is_int($timeout) || $timeout < 0 || $timeout > 60 || ! is_int($staleMinutes) || $staleMinutes < 1 || $staleMinutes > 1440) {
            throw new SheetImportException('Invalid importer lock or stale timeout configuration.');
        }
        $run = SyncRun::on($target->getConnectionName())->create(['target_id' => $target->id, 'snapshot_date' => $date, 'trigger_type' => $trigger, 'status' => SyncRunStatus::Pending]);
        $pdo = $main->getPdo();
        $connection = new MySqlConnection($pdo, $main->getDatabaseName(), '', $main->getConfig());
        $connection->setReadPdo($pdo);
        $connection->setReconnector(static function (): never {
            throw new SheetImportException('Snapshot session was lost; restart the complete import.');
        });
        if ($dispatcher = $main->getEventDispatcher()) {
            $connection->setEventDispatcher($dispatcher);
        }
        $lock = 'bulls:snapshot:'.sha1($main->getDatabaseName()."\0".$target->id."\0".$date);
        $acquired = false;
        try {
            $result = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lock, $timeout], false)->acquired;
            if ($result !== null && (int) $result === 0) {
                return $this->finish($run, SyncRunStatus::Skipped, 'Another worker holds the snapshot lock.');
            }
            if ((int) $result !== 1) {
                throw new SheetImportException('Unable to acquire the snapshot lock.');
            }
            $acquired = true;
            $scope = fn () => $connection->table('sync_runs')->where('target_id', $target->id)->where('snapshot_date', $date);
            $scope()->where('id', '!=', $run->id)->whereIn('status', ['pending', 'running'])
                ->whereRaw('COALESCE(heartbeat_at, started_at, created_at) < ?', [now()->subMinutes($staleMinutes)->format('Y-m-d H:i:s')])
                ->update(['status' => 'failed', 'error' => 'Import attempt expired due to inactivity.', 'finished_at' => now(), 'updated_at' => now()]);
            $fresh = $target->fresh(['tab.source']);
            if (! $fresh || ! $fresh->is_active || ! $fresh->tab->is_active || ! $fresh->tab->source->is_active) {
                return $this->finish($run, SyncRunStatus::Skipped, 'Source, tab or target is inactive.');
            }
            if ($scope()->where('id', '!=', $run->id)->where('status', 'running')->exists()) {
                return $this->finish($run, SyncRunStatus::Skipped, 'An unexpired import attempt is already running.');
            }
            if ($trigger === SyncRunTriggerType::Scheduled && $scope()->where('status', 'succeeded')->exists()) {
                return $this->finish($run, SyncRunStatus::Skipped, 'The scheduled snapshot already succeeded.');
            }
            $connection->table('sync_runs')->where('id', $run->id)->where('status', 'pending')->update(['status' => 'running', 'started_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now()]);
            $heartbeat = function () use ($connection, $run): void {
                $query = fn () => $connection->table('sync_runs')->where('id', $run->id)->where('status', 'running');
                if ($query()->update(['heartbeat_at' => now(), 'updated_at' => now()]) === 0 && ! $query()->exists()) {
                    throw new SheetImportException('The import attempt no longer owns its running state.');
                }
            };
            $import($connection, $heartbeat, $run);
            if ($run->fresh()->status !== SyncRunStatus::Succeeded) {
                throw new SheetImportException('Snapshot success was not committed with its rows.');
            }

            return $run->fresh();
        } catch (Throwable $exception) {
            $safe = $exception instanceof SheetImportException || $exception instanceof GoogleSheetsException || $exception instanceof SchemaManagerException;
            $message = $safe ? $exception->getMessage() : 'Import failed due to an internal or database error.';
            Log::error('Sheet import failed.', ['target_id' => $target->id, 'run_id' => $run->id, 'error' => $message]);

            return $this->finish($run, SyncRunStatus::Failed, $message, $exception instanceof RowValidationException ? $exception->failedRows : ($exception instanceof SheetImportException && $exception->sourceRow !== null ? 1 : 0));
        } finally {
            if ($acquired) {
                try {
                    $released = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false)->released;
                    if ((int) $released !== 1) {
                        Log::warning('Snapshot lock release was not confirmed.', ['run_id' => $run->id]);
                    }
                } catch (Throwable) {
                    Log::warning('Snapshot session ended before lock release.', ['run_id' => $run->id]);
                }
            }
        }
    }

    public function assertOwnership(Connection $connection, int $targetId, string $date, int $runId): void
    {
        $lock = 'bulls:snapshot:'.sha1($connection->getDatabaseName()."\0".$targetId."\0".$date);
        $owner = $connection->selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned', [$lock], false)->owned;
        $run = $connection->table('sync_runs')->where('id', $runId)->lockForUpdate()->first();
        if ((int) $owner !== 1 || $run === null || $run->status !== 'running') {
            throw new SheetImportException('The import attempt lost snapshot ownership.');
        }
    }

    private function finish(SyncRun $run, SyncRunStatus $status, string $message, int $rowsFailed = 0): SyncRun
    {
        $run->newQuery()->whereKey($run->id)->whereIn('status', ['pending', 'running'])->update(['status' => $status->value, 'error' => $message, 'rows_failed' => $rowsFailed, 'finished_at' => now(), 'updated_at' => now()]);

        return $run->fresh();
    }
}
