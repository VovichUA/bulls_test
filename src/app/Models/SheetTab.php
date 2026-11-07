<?php

namespace App\Models;

use Database\Factories\SheetTabFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['source_id', 'tab_name', 'header_row', 'is_active'])]
class SheetTab extends Model
{
    /** @use HasFactory<SheetTabFactory> */
    use HasFactory;

    public function source(): BelongsTo
    {
        return $this->belongsTo(SheetSource::class, 'source_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SyncTarget::class, 'tab_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'header_row' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
