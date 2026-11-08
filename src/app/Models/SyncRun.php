<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunTriggerType;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['failure_kind', 'config_fingerprint', 'dispatch_id', 'target_id', 'snapshot_date', 'trigger_type', 'status', 'rows_processed', 'rows_skipped', 'rows_failed', 'error', 'started_at', 'heartbeat_at', 'finished_at'])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $appends = ['rows_inserted', 'rows_updated'];

    protected function rowsInserted(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->rows_processed);
    }

    protected function rowsUpdated(): Attribute
    {
        return Attribute::get(fn (): int => 0);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SyncTarget::class, 'target_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot_date' => 'immutable_date',
            'trigger_type' => SyncRunTriggerType::class,
            'status' => SyncRunStatus::class,
            'rows_processed' => 'integer',
            'rows_skipped' => 'integer',
            'rows_failed' => 'integer',
            'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
