<?php

namespace Database\Factories;

use App\Models\SheetSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SheetSource> */
class SheetSourceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'spreadsheet_id' => fake()->unique()->uuid(),
            'is_active' => true,
        ];
    }
}
