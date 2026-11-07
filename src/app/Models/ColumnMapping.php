<?php

namespace App\Models;

use App\Enums\ColumnDataType;
use Database\Factories\ColumnMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['target_id', 'source_column', 'target_column', 'data_type', 'type_options', 'nullable', 'format'])]
class ColumnMapping extends Model
{
    /** @use HasFactory<ColumnMappingFactory> */
    use HasFactory;

    public function target(): BelongsTo
    {
        return $this->belongsTo(SyncTarget::class, 'target_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data_type' => ColumnDataType::class,
            'type_options' => 'array',
            'nullable' => 'boolean',
        ];
    }
}
