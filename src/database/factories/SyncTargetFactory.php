<?php

namespace Database\Factories;

use App\Models\SheetTab;
use App\Models\SyncTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SyncTarget> */
class SyncTargetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tab_id' => SheetTab::factory(),
            'target_table' => 'target_'.fake()->unique()->lexify('????????'),
            'unique_keys' => null,
            'is_active' => true,
        ];
    }
}
