<?php

namespace App\Services;

use App\Enums\SyncRunTriggerType;
use App\Exceptions\GoogleSheetsApiException;
use App\Exceptions\RowValidationException;
use App\Exceptions\SheetImportException;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\Importing\ImportConfigurationValidator;
use App\Services\Importing\SnapshotCoordinator;
use App\Services\Importing\ValueCaster;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

class SheetImporter
{
    public function __construct(
        private readonly GoogleSheetsClient $google,
        private readonly SchemaManager $schema,
        private readonly ImportConfigurationValidator $validator,
        private readonly ValueCaster $caster,
        private readonly SnapshotCoordinator $coordinator,
    ) {}

    /** @return Collection<int, SyncRun> */
    public function importAll(?string $snapshotDate = null, SyncRunTriggerType $trigger = SyncRunTriggerType::Scheduled): Collection
    {
        $date = $this->snapshotDate($snapshotDate, $trigger);
        $cache = [];
        $runs = collect();
        $targets = SyncTarget::query()->useWritePdo()->where('is_active', true)
            ->whereHas('tab', fn ($query) => $query->where('is_active', true)->whereHas('source', fn ($query) => $query->where('is_active', true)))
            ->orderBy('tab_id')->orderBy('id')->get();
        $lastTab = null;
        foreach ($targets as $target) {
            if ($lastTab !== $target->tab_id) {
                $cache = [];
                $lastTab = $target->tab_id;
            }
            $runs->push($this->perform($target, $date, $trigger, $cache));
        }

        return $runs;
    }

    public function importTarget(SyncTarget $target, ?string $snapshotDate = null, SyncRunTriggerType $trigger = SyncRunTriggerType::Manual): SyncRun
    {
        if (! $target->exists || ! $target->fresh()) {
            throw new SheetImportException('A persisted target is required.');
        }
        $cache = [];

        return $this->perform($target, $this->snapshotDate($snapshotDate, $trigger), $trigger, $cache);
    }

    /** @param list<int> $ids
     * @return Collection<int, SyncRun>
     */
    public function importPending(array $ids): Collection
    {
        $cache = [];
        $results = collect();
        $lastTab = null;
        foreach (SyncRun::whereIn('id', $ids)->where('status', 'pending')->with('target')->orderBy('target_id')->get()->sortBy(fn ($run) => $run->target->tab_id) as $run) {
            if ($lastTab !== $run->target->tab_id) {
                $cache = [];
                $lastTab = $run->target->tab_id;
            }
            $results->push($this->perform($run->target, $run->snapshot_date->format('Y-m-d'), $run->trigger_type, $cache, $run));
        }

        return $results;
    }

    public function snapshotDate(?string $date, SyncRunTriggerType $trigger): string
    {
        $today = now('Europe/Kyiv')->format('Y-m-d');
        $date ??= $today;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Kyiv'));
        $errors = DateTimeImmutable::getLastErrors();
        if (! $parsed || ($errors !== false && ($errors['error_count'] || $errors['warning_count'])) || $parsed->format('Y-m-d') !== $date || $date > $today || $date < '1000-01-01' || ($trigger === SyncRunTriggerType::Scheduled && $date !== $today)) {
            throw new SheetImportException('Snapshot date must be today for scheduled imports, or a valid past/today date for manual imports.');
        }

        return $date;
    }

    /** @param array<string, array|GoogleSheetsApiException> $cache */
    private function perform(SyncTarget $target, string $date, SyncRunTriggerType $trigger, array &$cache, ?SyncRun $attempt = null): SyncRun
    {
        return $this->coordinator->execute($target, $date, $trigger, function (Connection $connection, callable $heartbeat, SyncRun $run) use ($target, $date, &$cache): int {
            $signature = $this->configurationSignature($connection, $target->id);
            $target = $target->fresh(['tab.source']);
            $tab = $target->tab;
            $key = $tab->source->spreadsheet_id."\0".$tab->tab_name."\0".$tab->header_row;
            if (! array_key_exists($key, $cache)) {
                try {
                    $cache[$key] = $this->google->getData($tab->source->spreadsheet_id, $tab->tab_name, $tab->header_row, $heartbeat);
                } catch (GoogleSheetsApiException $exception) {
                    if (! $exception->retryable && in_array($exception->getCode(), [400, 403, 404], true)) {
                        $cache[$key] = $exception;
                    }
                    throw $exception;
                }
            }
            if ($cache[$key] instanceof GoogleSheetsApiException) {
                throw $cache[$key];
            }
            $data = $cache[$key];
            $configuration = $this->validator->validate($target, $data);
            $records = [];
            $seen = [];
            $lastBeat = now()->timestamp;
            $errors = [];
            $totalErrors = 0;
            $failedRows = 0;
            foreach ($data['rows'] as $sourceRow => $values) {
                if (now()->timestamp - $lastBeat >= 30) {
                    $heartbeat();
                    $lastBeat = now()->timestamp;
                }
                $rowFailed = false;
                $report = function (string $column, string $reason) use (&$errors, &$totalErrors, &$rowFailed, $sourceRow): void {
                    $rowFailed = true;
                    $totalErrors++;
                    if (count($errors) < 20) {
                        $errors[] = ['row' => $sourceRow, 'column' => $column, 'reason' => $reason];
                    }
                };
                $record = [];
                foreach ($configuration['mappings'] as $mapping) {
                    try {
                        $value = $mapping['missing'] ? null : ($values[$mapping['source']] ?? null);
                        $record[$mapping['column']] = $this->caster->cast($value, $mapping['type'], $mapping['options'], $mapping['format']);
                        if ($record[$mapping['column']] === null && ! $mapping['nullable']) {
                            throw new SheetImportException('Required mapped value is empty.');
                        }
                    } catch (SheetImportException $exception) {
                        $report($mapping['column'], $exception->getMessage());
                    }
                }
                if (! $rowFailed && $configuration['unique_keys'] !== []) {
                    $tuple = [];
                    foreach ($configuration['unique_keys'] as $column) {
                        if ($record[$column] === null) {
                            $report($column, 'A configured unique key value is empty.');
                        }
                        $tuple[] = $record[$column];
                    }
                    if (! $rowFailed) {
                        $keyHash = hash('sha256', json_encode($tuple, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                        if (isset($seen[$keyHash])) {
                            $report(implode(',', array_slice($configuration['unique_keys'], 0, 3)), 'Duplicate configured business key.');
                        }
                        $seen[$keyHash] = true;
                    }
                }
                if ($rowFailed) {
                    $failedRows++;

                    continue;
                }
                ksort($record);
                $hash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $records[] = $record + ['sync_target_id' => $target->id, 'snapshot_date' => $date, 'source_row' => $sourceRow, 'row_hash' => $hash, 'created_at' => now()->format('Y-m-d H:i:s'), 'updated_at' => now()->format('Y-m-d H:i:s')];
            }
            if ($failedRows > 0) {
                throw new RowValidationException($failedRows, $errors, $totalErrors);
            }
            $chunkSize = config('sheet-import.chunk_size');
            if (! is_int($chunkSize) || $chunkSize < 1 || $chunkSize > 1000) {
                throw new SheetImportException('Import chunk size must be between 1 and 1000.');
            }
            $heartbeat();
            $this->assertConfiguration($connection, $target->id, $signature);
            $this->schema->ensure($target);
            $this->assertConfiguration($connection, $target->id, $signature);
            $heartbeat();
            $connection->transaction(function () use ($connection, $target, $date, $records, $run, $chunkSize, $heartbeat, $signature): void {
                $this->coordinator->assertOwnership($connection, $target->id, $date, $run->id);
                $this->assertConfiguration($connection, $target->id, $signature, true);
                $connection->table($target->target_table)->where('sync_target_id', $target->id)->where('snapshot_date', $date)->delete();
                foreach (array_chunk($records, $chunkSize) as $chunk) {
                    $connection->table($target->target_table)->insert($chunk);
                    $heartbeat();
                }
                $this->coordinator->assertOwnership($connection, $target->id, $date, $run->id);
                $connection->table('sync_runs')->where('id', $run->id)->where('status', 'running')->update(['status' => 'succeeded', 'rows_processed' => count($records), 'finished_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now()]);
            });

            return count($records);
        }, $attempt);
    }

    private function assertConfiguration(Connection $connection, int $targetId, string $signature, bool $lock = false): void
    {
        if ($this->configurationSignature($connection, $targetId, $lock) !== $signature) {
            throw new SheetImportException('Configuration changed during import. Retry with the current settings.');
        }
    }

    private function configurationSignature(Connection $connection, int $targetId, bool $lock = false): string
    {
        $read = function (string $table, int $id) use ($connection, $lock): object {
            $query = $connection->table($table)->where('id', $id);
            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->first() ?? throw new SheetImportException('Import configuration no longer exists.');
        };
        $target = $read('sync_targets', $targetId);
        $tab = $read('sheet_tabs', $target->tab_id);
        $source = $read('sheet_sources', $tab->source_id);
        $mappings = $connection->table('column_mappings')->where('target_id', $targetId)->orderBy('id');
        if ($lock) {
            $mappings->lockForUpdate();
        }

        return hash('sha256', serialize([$target, $tab, $source, $mappings->get()->all()]));
    }
}
