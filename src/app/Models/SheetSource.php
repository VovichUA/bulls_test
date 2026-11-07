<?php

namespace App\Models;

use Database\Factories\SheetSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'spreadsheet_id', 'is_active'])]
class SheetSource extends Model
{
    /** @use HasFactory<SheetSourceFactory> */
    use HasFactory;

    public function tabs(): HasMany
    {
        return $this->hasMany(SheetTab::class, 'source_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
