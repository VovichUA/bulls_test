<?php

namespace Database\Seeders;

use App\Enums\ColumnDataType;
use App\Models\SheetSource;
use Illuminate\Database\Seeder;

class SheetConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $source = SheetSource::query()->firstOrCreate(
            ['spreadsheet_id' => 'REPLACE_WITH_SPREADSHEET_ID'],
            ['name' => 'Sales demo', 'is_active' => false],
        );

        $tab = $source->tabs()->firstOrCreate(
            ['tab_name' => 'Sales'],
            ['header_row' => 1, 'is_active' => true],
        );

        $target = $tab->targets()->firstOrCreate(
            ['target_table' => 'sales'],
            ['unique_keys' => null, 'is_active' => true],
        );

        $mappings = [
            ['date', 'date', ColumnDataType::Date, null, 'Y-m-d'],
            ['product_name', 'name', ColumnDataType::String, ['length' => 255], null],
            ['price', 'product_price', ColumnDataType::Decimal, ['precision' => 12, 'scale' => 2], null],
            ['amount', 'amount', ColumnDataType::Integer, null, null],
        ];

        foreach ($mappings as [$sourceColumn, $targetColumn, $type, $options, $format]) {
            $target->mappings()->firstOrCreate(
                ['target_column' => $targetColumn],
                [
                    'source_column' => $sourceColumn,
                    'data_type' => $type,
                    'type_options' => $options,
                    'nullable' => false,
                    'format' => $format,
                ],
            );
        }
    }
}
