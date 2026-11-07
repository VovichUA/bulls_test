<?php

namespace App\Models;

use Database\Factories\SyncTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tab_id', 'target_table', 'unique_keys', 'is_active'])]
class SyncTarget extends Model
{
    /** @use HasFactory<SyncTargetFactory> */
    use HasFactory;

    public function tab(): BelongsTo
    {
        return $this->belongsTo(SheetTab::class, 'tab_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ColumnMapping::class, 'target_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class, 'target_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unique_keys' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
