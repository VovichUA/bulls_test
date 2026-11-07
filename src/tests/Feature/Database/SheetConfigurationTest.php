<?php

namespace Tests\Feature\Database;

use App\Enums\ColumnDataType;
use App\Models\ColumnMapping;
use App\Models\SheetSource;
use App\Models\SheetTab;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

class SheetConfigurationTest extends DatabaseTestCase
{
    public function test_one_tab_has_independent_targets_and_mappings(): void
    {
        $tab = SheetTab::factory()->create();
        $sales = SyncTarget::factory()->for($tab, 'tab')->create(['target_table' => 'sales']);
        $products = SyncTarget::factory()->for($tab, 'tab')->create(['target_table' => 'products']);
        $saleMapping = ColumnMapping::factory()->for($sales, 'target')->create(['target_column' => 'product_price']);
        $productMapping = ColumnMapping::factory()->for($products, 'target')->create(['target_column' => 'name']);
        $this->assertCount(2, $tab->targets);
        $this->assertTrue($tab->source->tabs->sole()->is($tab));
        $this->assertTrue($sales->tab->is($tab));
        $this->assertTrue($sales->mappings->sole()->is($saleMapping));
        $this->assertTrue($products->mappings->sole()->is($productMapping));
        $this->assertTrue($saleMapping->target->is($sales));
    }

    public function test_defaults_and_json_casts_are_preserved(): void
    {
        $source = SheetSource::query()->create(['name' => 'Sales', 'spreadsheet_id' => 'demo']);
        $tab = $source->tabs()->create(['tab_name' => 'Sales']);
        $target = $tab->targets()->create(['target_table' => 'sales']);
        $mapping = $target->mappings()->create([
            'source_column' => 'Price', 'target_column' => 'price', 'data_type' => ColumnDataType::Decimal,
            'type_options' => ['precision' => 12, 'scale' => 2],
        ]);
        $this->assertTrue($source->fresh()->is_active);
        $this->assertSame(1, $tab->fresh()->header_row);
        $this->assertTrue($tab->fresh()->is_active);
        $this->assertTrue($target->fresh()->is_active);
        $this->assertNull($target->fresh()->unique_keys);
        $this->assertTrue($mapping->fresh()->nullable);
        $this->assertSame(ColumnDataType::Decimal, $mapping->fresh()->data_type);
        $this->assertEquals(['precision' => 12, 'scale' => 2], $mapping->fresh()->type_options);
        $this->assertNull($mapping->fresh()->format);
        $target->update(['unique_keys' => []]);
        $this->assertSame([], $target->fresh()->unique_keys);
        $target->update(['unique_keys' => ['price']]);
        $this->assertSame(['price'], $target->fresh()->unique_keys);
    }

    #[DataProvider('uniqueConstraints')]
    public function test_duplicate_configuration_is_rejected(string $model, array $overrides): void
    {
        $record = $model::factory()->create($overrides);
        $copy = $record->replicate();
        $this->expectException(QueryException::class);
        $copy->save();
    }

    /** @return array<string, array{class-string, array<string, mixed>}> */
    public static function uniqueConstraints(): array
    {
        return [
            'file' => [SheetSource::class, ['spreadsheet_id' => 'same-file']],
            'tab within file' => [SheetTab::class, ['tab_name' => 'Sales']],
            'target within tab' => [SyncTarget::class, ['target_table' => 'sales']],
            'column within target' => [ColumnMapping::class, ['target_column' => 'price']],
        ];
    }

    public function test_identical_names_are_allowed_under_different_parents(): void
    {
        $tabs = SheetTab::factory()->count(2)->create(['tab_name' => 'Sales']);
        $first = SyncTarget::factory()->for($tabs[0], 'tab')->create(['target_table' => 'sales', 'unique_keys' => ['date', 'name']]);
        $second = SyncTarget::factory()->for($tabs[1], 'tab')->create(['target_table' => 'sales', 'unique_keys' => ['order_id']]);
        ColumnMapping::factory()->for($first, 'target')->create(['target_column' => 'name']);
        ColumnMapping::factory()->for($second, 'target')->create(['target_column' => 'name']);
        $this->assertDatabaseCount('sheet_tabs', 2);
        $this->assertDatabaseCount('sync_targets', 2);
        $this->assertDatabaseCount('column_mappings', 2);
        $this->assertSame(['order_id'], $second->fresh()->unique_keys);
    }

    #[DataProvider('foreignKeys')]
    public function test_nonexistent_parent_is_rejected(string $model, string $foreignKey): void
    {
        $this->expectException(QueryException::class);
        $model::factory()->create([$foreignKey => 999999]);
    }

    /** @return array<string, array{class-string, string}> */
    public static function foreignKeys(): array
    {
        return [
            'tab' => [SheetTab::class, 'source_id'],
            'target' => [SyncTarget::class, 'tab_id'],
            'mapping' => [ColumnMapping::class, 'target_id'],
            'run' => [SyncRun::class, 'target_id'],
        ];
    }

    public function test_deleting_unused_configuration_cascades_only_its_children(): void
    {
        $mapping = ColumnMapping::factory()->create();
        $target = $mapping->target;
        $tab = $target->tab;
        $otherMapping = ColumnMapping::factory()->create();
        $tab->source->delete();
        $this->assertModelMissing($mapping);
        $this->assertModelMissing($target);
        $this->assertModelMissing($tab);
        $this->assertModelExists($otherMapping);
    }

    public function test_all_configuration_tables_use_innodb(): void
    {
        foreach (['sheet_sources', 'sheet_tabs', 'sync_targets', 'column_mappings', 'sync_runs'] as $table) {
            $metadata = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            $this->assertSame('InnoDB', $metadata->engine);
        }
    }
}
