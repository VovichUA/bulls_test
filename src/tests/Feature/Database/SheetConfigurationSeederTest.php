<?php

namespace Tests\Feature\Database;

use App\Models\SheetSource;
use Database\Seeders\SheetConfigurationSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\DatabaseTestCase;

class SheetConfigurationSeederTest extends DatabaseTestCase
{
    public function test_demo_is_repeatable_inactive_and_does_not_create_destination_table(): void
    {
        $this->seed(SheetConfigurationSeeder::class);
        $source = SheetSource::query()->sole();
        $source->update(['name' => 'My customized demo']);
        $this->seed(SheetConfigurationSeeder::class);
        $this->assertDatabaseCount('sheet_sources', 1);
        $this->assertDatabaseCount('sheet_tabs', 1);
        $this->assertDatabaseCount('sync_targets', 1);
        $this->assertDatabaseCount('column_mappings', 4);
        $this->assertSame('My customized demo', $source->fresh()->name);
        $this->assertFalse($source->fresh()->is_active);
        $this->assertSame('REPLACE_WITH_SPREADSHEET_ID', $source->spreadsheet_id);
        $target = $source->tabs->sole()->targets->sole();
        $this->assertSame('sales', $target->target_table);
        $this->assertNull($target->unique_keys);
        $this->assertEquals([
            'date' => 'date', 'product_name' => 'name', 'price' => 'product_price', 'amount' => 'amount',
        ], $target->mappings->pluck('target_column', 'source_column')->all());
        $this->assertFalse(Schema::hasTable('sales'));
    }
}
