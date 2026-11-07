<?php

namespace Database\Factories;

use App\Enums\ColumnDataType;
use App\Models\ColumnMapping;
use App\Models\SyncTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ColumnMapping> */
class ColumnMappingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'target_id' => SyncTarget::factory(),
            'source_column' => fake()->word(),
            'target_column' => 'column_'.fake()->unique()->lexify('????????'),
            'data_type' => ColumnDataType::String,
            'type_options' => ['length' => 255],
            'nullable' => true,
            'format' => null,
        ];
    }
}
