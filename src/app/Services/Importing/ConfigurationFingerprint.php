<?php

namespace App\Services\Importing;

use App\Exceptions\ImportConfigurationException;
use Illuminate\Database\Connection;

class ConfigurationFingerprint
{
    public function read(Connection $connection, int $targetId): string
    {
        $target = $connection->table('sync_targets')->useWritePdo()->where('id', $targetId)->first(['id', 'tab_id', 'target_table', 'unique_keys', 'is_active']);
        $tab = $target ? $connection->table('sheet_tabs')->useWritePdo()->where('id', $target->tab_id)->first(['id', 'source_id', 'tab_name', 'header_row', 'is_active']) : null;
        $source = $tab ? $connection->table('sheet_sources')->useWritePdo()->where('id', $tab->source_id)->first(['id', 'spreadsheet_id', 'is_active']) : null;
        if (! $source) {
            throw new ImportConfigurationException('Import configuration no longer exists.');
        }
        $target->unique_keys = $target->unique_keys === null ? null : json_decode($target->unique_keys, true, flags: JSON_THROW_ON_ERROR);
        $mappings = $connection->table('column_mappings')->useWritePdo()->where('target_id', $targetId)->orderBy('target_column')->orderBy('id')
            ->get(['source_column', 'target_column', 'data_type', 'type_options', 'nullable', 'format'])->map(function ($mapping) {
                $mapping->type_options = $mapping->type_options === null ? null : json_decode($mapping->type_options, true, flags: JSON_THROW_ON_ERROR);

                return (array) $mapping;
            })->all();

        return hash('sha256', json_encode($this->canonical([(array) $target, (array) $tab, (array) $source, $mappings]), JSON_THROW_ON_ERROR));
    }

    private function canonical(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $values;
    }
}
