<?php

namespace Database\Factories;

use App\Models\SheetSource;
use App\Models\SheetTab;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SheetTab> */
class SheetTabFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'source_id' => SheetSource::factory(),
            'tab_name' => 'Tab '.fake()->unique()->word(),
            'header_row' => 1,
            'is_active' => true,
        ];
    }
}
