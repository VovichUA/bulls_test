<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunTriggerType;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SyncRun> */
class SyncRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'target_id' => SyncTarget::factory(),
            'snapshot_date' => today('Europe/Kyiv')->toDateString(),
            'trigger_type' => SyncRunTriggerType::Scheduled,
            'status' => SyncRunStatus::Pending,
        ];
    }
}
