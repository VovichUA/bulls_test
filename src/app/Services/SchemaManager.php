<?php

namespace App\Services;

use App\Enums\ColumnDataType;
use App\Exceptions\SchemaLockTimeoutException;
use App\Exceptions\SchemaManagerException;
use App\Models\SyncTarget;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class SchemaManager
{
    private const OWNER = 'bulls:sheets:snapshot:v1';

    private const SERVICE_COLUMNS = ['id', 'sync_target_id', 'snapshot_date', 'source_row', 'row_hash', 'created_at', 'updated_at', 'source_id', 'sync_run_id'];

    private const PROTECTED_TABLES = ['users', 'migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    public function __construct(private readonly int $lockTimeoutSeconds = 10) {}

    public function ensure(SyncTarget $target): void
    {
        $connection = $target->getConnection();
        if ($connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== '') {
            throw new SchemaManagerException('SchemaManager requires MySQL without a table prefix.');
        }
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new SchemaManagerException('Run SchemaManager before starting the snapshot transaction.');
        }
        if ($this->lockTimeoutSeconds < 0 || $this->lockTimeoutSeconds > 60) {
            throw new SchemaManagerException('DDL lock timeout must be between 0 and 60 seconds.');
        }
        if (! $target->exists || ! ($target = $target->fresh())) {
            throw new SchemaManagerException('A persisted sync target is required.');
        }
        $table = $target->target_table;
        $this->validateIdentifier($table);
        if (in_array($table, self::PROTECTED_TABLES, true) || preg_match('/^(sheet_|sync_|column_mapping)/', $table)) {
            throw new SchemaManagerException('The target table is reserved for the application.');
        }
        $definitions = $this->definitions($target);
        $connection = $this->pinnedConnection($connection);
        $lock = 'sheets_schema:'.sha1($connection->getDatabaseName()."\0".$table);
        $acquired = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lock, $this->lockTimeoutSeconds], false)->acquired;
        if ($acquired !== null && (int) $acquired === 0) {
            throw new SchemaLockTimeoutException('Timed out waiting for the target table schema lock. Retry later.');
        }
        if ((int) $acquired !== 1) {
            throw new SchemaManagerException('Could not acquire the target table schema lock.', retryable: true);
        }
        try {
            $schema = $connection->getSchemaBuilder();
            if (! $schema->hasTable($table)) {
                $schema->create($table, function (Blueprint $blueprint) use ($definitions): void {
                    $blueprint->engine('InnoDB');
                    $blueprint->charset('utf8mb4');
                    $blueprint->collation('utf8mb4_unicode_ci');
                    $blueprint->comment(self::OWNER);
                    $blueprint->id();
                    $blueprint->unsignedBigInteger('sync_target_id');
                    $blueprint->date('snapshot_date');
                    $blueprint->unsignedBigInteger('source_row');
                    $blueprint->char('row_hash', 64);
                    $blueprint->timestamps();
                    $blueprint->unique(['sync_target_id', 'snapshot_date', 'source_row'], 'snapshot_rows_unique');
                    $this->addColumns($blueprint, $definitions);
                });

                return;
            }
            $this->verifyTable($connection, $schema, $table);
            $columns = array_column($schema->getColumns($table), null, 'name');
            $missing = [];
            foreach ($definitions as $name => $definition) {
                if ($schema->hasColumn($table, $name)) {
                    $this->verifyColumn($columns[$name], $definition['sql'], true, $name);
                } else {
                    $missing[$name] = $definition;
                }
            }
            if ($missing !== []) {
                $schema->table($table, fn (Blueprint $blueprint) => $this->addColumns($blueprint, $missing));
            }
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1118, 1074], true)) {
                throw new SchemaManagerException('The target schema exceeds MySQL row or column size limits. Reduce string lengths or use TEXT mappings.');
            }
            throw $exception;
        } finally {
            if ((int) $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false)->released !== 1) {
                throw new SchemaManagerException('The schema lock session was lost. Retry the complete schema operation.', retryable: true);
            }
        }
    }

    /** Keep Schema Builder on the locked writer PDO; never retry DDL on a new session. */
    private function pinnedConnection(Connection $connection): MySqlConnection
    {
        $pdo = $connection->getPdo();
        $pinned = new MySqlConnection($pdo, $connection->getDatabaseName(), '', $connection->getConfig());
        $pinned->setReadPdo($pdo);
        $pinned->setReconnector(static function (): never {
            throw new SchemaManagerException('The schema lock session was lost. Retry the complete schema operation.', retryable: true);
        });
        if ($dispatcher = $connection->getEventDispatcher()) {
            $pinned->setEventDispatcher($dispatcher);
        }

        return $pinned;
    }

    private function validateIdentifier(string $name): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) {
            throw new SchemaManagerException('Table and column names must be lowercase identifiers of 1 to 64 characters.');
        }
    }

    /** @return array<string, array{type: ColumnDataType, first: int, second: int, sql: string}> */
    private function definitions(SyncTarget $target): array
    {
        $mappings = $target->mappings()->useWritePdo()->orderBy('id')->get();
        if ($mappings->isEmpty()) {
            throw new SchemaManagerException('At least one column mapping is required.');
        }
        $definitions = [];
        foreach ($mappings as $mapping) {
            $name = $mapping->target_column;
            $this->validateIdentifier($name);
            if (in_array($name, self::SERVICE_COLUMNS, true)) {
                throw new SchemaManagerException('A mapping cannot overwrite a snapshot service column.');
            }
            $type = ColumnDataType::tryFrom($mapping->getRawOriginal('data_type'));
            if ($type === null) {
                throw new SchemaManagerException('Unsupported mapping data type.');
            }
            $options = $mapping->type_options ?? [];
            if (! is_array($options) || ($options !== [] && array_is_list($options))) {
                throw new SchemaManagerException('Mapping type_options must be an object.');
            }
            $first = 0;
            $second = 0;
            $sql = match ($type) {
                ColumnDataType::String => 'varchar('.($first = $this->integerOption($options, 'length', 255, 1, 255)).')',
                ColumnDataType::Decimal => 'decimal('.($first = $this->integerOption($options, 'precision', 15, 1, 65)).','.($second = $this->integerOption($options, 'scale', 4, 0, 30)).')',
                ColumnDataType::Text => 'text',
                ColumnDataType::Integer => 'bigint',
                ColumnDataType::Date => 'date',
                ColumnDataType::Datetime => 'datetime',
                ColumnDataType::Boolean => 'tinyint(1)',
            };
            if ($type === ColumnDataType::Decimal && $second > $first) {
                throw new SchemaManagerException('Decimal scale cannot exceed precision.');
            }
            $definitions[$name] = ['type' => $type, 'first' => $first, 'second' => $second, 'sql' => $sql];
        }

        return $definitions;
    }

    /** @param array<string, mixed> $options */
    private function integerOption(array $options, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = array_key_exists($key, $options) ? $options[$key] : $default;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new SchemaManagerException('Invalid mapping option: '.$key.'.');
        }

        return $value;
    }

    /** @param array<string, array{type: ColumnDataType, first: int, second: int, sql: string}> $definitions */
    private function addColumns(Blueprint $table, array $definitions): void
    {
        foreach ($definitions as $name => $definition) {
            $column = match ($definition['type']) {
                ColumnDataType::String => $table->string($name, $definition['first']),
                ColumnDataType::Text => $table->text($name),
                ColumnDataType::Integer => $table->bigInteger($name),
                ColumnDataType::Decimal => $table->decimal($name, $definition['first'], $definition['second']),
                ColumnDataType::Date => $table->date($name),
                ColumnDataType::Datetime => $table->dateTime($name),
                ColumnDataType::Boolean => $table->boolean($name),
            };
            $column->nullable();
        }
    }

    private function verifyTable(Connection $connection, Builder $schema, string $table): void
    {
        $metadata = $connection->selectOne('SELECT TABLE_COMMENT AS owner, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table], false);
        if ($metadata === null || $metadata->owner !== self::OWNER || $metadata->engine !== 'InnoDB') {
            throw new SchemaManagerException('Existing target table is not a managed InnoDB snapshot table.');
        }
        $columns = array_column($schema->getColumns($table), null, 'name');
        foreach (['id' => 'bigint unsigned', 'sync_target_id' => 'bigint unsigned', 'snapshot_date' => 'date', 'source_row' => 'bigint unsigned', 'row_hash' => 'char(64)', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'] as $name => $type) {
            $this->verifyColumn($columns[$name] ?? [], $type, in_array($name, ['created_at', 'updated_at'], true), $name);
        }
        if (! $columns['id']['auto_increment'] || ! $schema->hasIndex($table, ['id'], 'primary') || ! $schema->hasIndex($table, ['sync_target_id', 'snapshot_date', 'source_row'], 'unique')) {
            throw new SchemaManagerException('The target table is missing its snapshot primary/unique index.');
        }
    }

    /** @param array<string, mixed> $column */
    private function verifyColumn(array $column, string $type, bool $nullable, string $name): void
    {
        if (($column['type'] ?? null) !== $type || ($column['nullable'] ?? null) !== $nullable || ($column['generation'] ?? null) !== null) {
            throw new SchemaManagerException('Incompatible existing column: '.$name.'. Automatic type changes are forbidden.');
        }
    }
}
