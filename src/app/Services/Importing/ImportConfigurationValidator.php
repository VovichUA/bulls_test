<?php

namespace App\Services\Importing;

use App\Enums\ColumnDataType;
use App\Exceptions\SheetImportException;
use App\Models\SyncTarget;
use App\Services\GoogleSheets\HeaderNormalizer;
use Illuminate\Support\Facades\Log;

class ImportConfigurationValidator
{
    public function __construct(private readonly HeaderNormalizer $normalizer, private readonly ValueCaster $caster) {}

    /** @param array{headers: array, ambiguous: array, rows: array} $data
     * @return array{mappings: list<array{source: string, column: string, type: ColumnDataType, options: array, format: ?string, nullable: bool, missing: bool}>, unique_keys: list<string>}
     */
    public function validate(SyncTarget $target, array $data): array
    {
        $mappings = [];
        foreach ($target->mappings()->useWritePdo()->orderBy('id')->get() as $mapping) {
            $source = $this->normalizer->normalize($mapping->source_column);
            $type = ColumnDataType::tryFrom($mapping->getRawOriginal('data_type'));
            $options = $mapping->type_options ?? [];
            if ($source === '' || $type === null || ! is_array($options)) {
                throw new SheetImportException('Invalid source column, data type or type_options.');
            }
            $this->caster->validateOptions($type, $options, $mapping->format);
            if (in_array($source, $data['ambiguous'], true)) {
                throw new SheetImportException('Ambiguous mapped source column: '.$source.'.');
            }
            $missing = ! in_array($source, $data['headers'], true);
            if ($missing && ! $mapping->nullable) {
                throw new SheetImportException('Required source column is missing: '.$source.'.');
            }
            if ($missing) {
                Log::warning('Optional source column is missing; importing NULL.', ['target_id' => $target->id, 'source_column' => $source]);
            }
            $mappings[] = ['source' => $source, 'column' => $mapping->target_column, 'type' => $type, 'options' => $options, 'format' => $mapping->format, 'nullable' => $mapping->nullable, 'missing' => $missing];
        }
        if ($mappings === []) {
            throw new SheetImportException('At least one mapping is required.');
        }
        $keys = $target->unique_keys ?? [];
        if (! is_array($keys) || ! array_is_list($keys)) {
            throw new SheetImportException('unique_keys must be a list of mapped destination names.');
        }
        $seen = [];
        foreach ($keys as $key) {
            if (! is_string($key) || ! in_array($key, array_column($mappings, 'column'), true) || in_array($key, $seen, true)) {
                throw new SheetImportException('unique_keys must contain distinct mapped destination names.');
            }
            $seen[] = $key;
        }

        return ['mappings' => $mappings, 'unique_keys' => $keys];
    }
}
