<?php

namespace Tests\Feature\Database;

use App\Models\ColumnMapping;
use App\Models\SheetSource;
use App\Models\SheetTab;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use App\Models\User;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\Importing\ConfigurationFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\DatabaseTestCase;

class AdminConfigurationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['approved_at' => now()]));
    }

    public function test_source_accepts_sheets_url_and_stores_only_id(): void
    {
        $this->post('/sources', ['name' => 'Sales', 'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/abc_123-XYZ/edit?usp=sharing', 'is_active' => '1'])->assertRedirect();
        $this->assertDatabaseHas('sheet_sources', ['name' => 'Sales', 'spreadsheet_id' => 'abc_123-XYZ', 'is_active' => 1]);
        $this->post('/sources', ['name' => 'Bad', 'spreadsheet_id' => 'https://evil.example/spreadsheets/d/foo/edit'])->assertSessionHasErrors('spreadsheet_id');
        $this->assertDatabaseCount('sheet_sources', 1);
    }

    public function test_source_and_tab_update_validates_unique_scope_and_header_row(): void
    {
        $source = SheetSource::factory()->create();
        $this->post('/sources/'.$source->id.'/tabs', ['tab_name' => 'Sales', 'header_row' => 3, 'is_active' => 1])->assertRedirect();
        $tab = $source->tabs()->sole();
        $this->post('/sources/'.$source->id.'/tabs', ['tab_name' => 'Sales', 'header_row' => 1])->assertSessionHasErrors('tab_name');
        $this->put('/sources/'.$source->id.'/tabs/'.$tab->id, ['tab_name' => 'New', 'header_row' => 0])->assertSessionHasErrors('header_row');
        $this->put('/sources/'.$source->id.'/tabs/'.$tab->id, ['tab_name' => 'New', 'header_row' => 2, 'is_active' => 0])->assertRedirect();
        $this->assertDatabaseHas('sheet_tabs', ['id' => $tab->id, 'tab_name' => 'New', 'header_row' => 2, 'is_active' => 0]);
    }

    public function test_scoped_routes_reject_a_tab_or_target_under_the_wrong_parent(): void
    {
        $tab = SheetTab::factory()->create();
        $other = SheetSource::factory()->create();
        $target = SyncTarget::factory()->for($tab, 'tab')->create();
        $otherTab = SheetTab::factory()->create();
        $this->get('/sources/'.$other->id.'/tabs/'.$tab->id.'/edit')->assertNotFound();
        $this->delete('/sources/'.$other->id.'/tabs/'.$tab->id)->assertNotFound();
        $this->get('/tabs/'.$otherTab->id.'/targets/'.$target->id.'/edit')->assertNotFound();
        $this->assertDatabaseHas('sheet_tabs', ['id' => $tab->id]);
    }

    public function test_creates_multiple_targets_and_normalizes_mapping_with_optional_keys(): void
    {
        $tab = SheetTab::factory()->create();
        $payload = $this->payload();
        $this->post('/tabs/'.$tab->id.'/targets', $payload)->assertRedirect();
        $target = SyncTarget::sole();
        $this->assertSame(['name'], $target->unique_keys);
        $mapping = $target->mappings()->sole();
        $this->assertSame('product name', $mapping->source_column);
        $this->assertSame('name', $mapping->target_column);
        $this->assertFalse(Schema::hasTable('ui_sales'));
        $payload['target_table'] = 'ui_summary';
        $payload['mappings'][0]['key'] = 0;
        $this->post('/tabs/'.$tab->id.'/targets', $payload)->assertRedirect();
        $this->assertDatabaseCount('sync_targets', 2);
        $this->assertNull(SyncTarget::where('target_table', 'ui_summary')->sole()->unique_keys);
    }

    public function test_rejects_reserved_and_newline_identifiers_and_duplicate_destinations(): void
    {
        $tab = SheetTab::factory()->create();
        foreach (['users', 'sheet_secret', "sales\n", 'sales;drop_table'] as $name) {
            $payload = $this->payload();
            $payload['target_table'] = $name;
            $this->post('/tabs/'.$tab->id.'/targets', $payload)->assertSessionHasErrors('target_table');
        }
        $payload = $this->payload();
        $payload['mappings'][0]['target_column'] = 'snapshot_date';
        $this->post('/tabs/'.$tab->id.'/targets', $payload)->assertSessionHasErrors('mappings.0.target_column');
        $payload = $this->payload();
        $payload['mappings'][] = $payload['mappings'][0];
        $this->post('/tabs/'.$tab->id.'/targets', $payload)->assertSessionHasErrors('mappings.0.target_column');
        $this->assertDatabaseCount('sync_targets', 0);
    }

    public function test_decimal_options_are_validated_and_saved_without_ddl(): void
    {
        $tab = SheetTab::factory()->create();
        $payload = $this->payload();
        $payload['mappings'][0]['data_type'] = 'decimal';
        $payload['mappings'][0]['type_options_json'] = '{"precision":12,"scale":2,"decimal_separator":","}';
        $this->post('/tabs/'.$tab->id.'/targets', $payload)->assertRedirect();
        $this->assertSame(2, ColumnMapping::sole()->type_options['scale']);
        $payload['target_table'] = 'ui_other';
        $payload['mappings'][0]['type_options_json'] = '{"precision":2,"scale":3}';
        $this->post('/tabs/'.$tab->id.'/targets', $payload)->assertSessionHasErrors('mappings.0.type_options_json');
        $this->assertDatabaseCount('sync_targets', 1);
    }

    public function test_target_table_is_unique_within_tab_but_can_be_shared_between_tabs(): void
    {
        $target = $this->target();
        $this->post('/tabs/'.$target->tab_id.'/targets', $this->payload())->assertSessionHasErrors('target_table');
        $sibling = SyncTarget::factory()->for($target->tab, 'tab')->create(['target_table' => 'ui_other']);
        $payload = $this->payload();
        $payload['config_fingerprint'] = app(ConfigurationFingerprint::class)->read(DB::connection(), $sibling->id);
        $this->put('/tabs/'.$sibling->tab_id.'/targets/'.$sibling->id, $payload)->assertSessionHasErrors('target_table');
        $payload['config_fingerprint'] = app(ConfigurationFingerprint::class)->read(DB::connection(), $target->id);
        $this->put('/tabs/'.$target->tab_id.'/targets/'.$target->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $otherTab = SheetTab::factory()->create();
        $this->post('/tabs/'.$otherTab->id.'/targets', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('sync_targets', 3);
    }

    public function test_stale_mapping_edit_is_rejected_and_history_prevents_table_rename(): void
    {
        $target = $this->target();
        $payload = $this->payload();
        $payload['config_fingerprint'] = str_repeat('0', 64);
        $this->put('/tabs/'.$target->tab_id.'/targets/'.$target->id, $payload)->assertSessionHasErrors('config_fingerprint');
        $this->assertSame('original', $target->mappings()->sole()->target_column);
        SyncRun::factory()->for($target, 'target')->create();
        $payload['config_fingerprint'] = app(ConfigurationFingerprint::class)->read(DB::connection(), $target->id);
        $payload['target_table'] = 'renamed';
        $this->put('/tabs/'.$target->tab_id.'/targets/'.$target->id, $payload)->assertSessionHasErrors('target_table');
        $this->assertSame('ui_sales', $target->fresh()->target_table);
    }

    public function test_mapping_replacement_rolls_back_on_insert_failure(): void
    {
        $target = $this->target();
        $payload = $this->payload();
        $payload['config_fingerprint'] = app(ConfigurationFingerprint::class)->read(DB::connection(), $target->id);
        ColumnMapping::creating(function () {
            throw new \RuntimeException('Injected insert failure.');
        });
        $this->put('/tabs/'.$target->tab_id.'/targets/'.$target->id, $payload)->assertStatus(500);
        $this->assertSame('original', $target->mappings()->sole()->target_column);
    }

    public function test_clean_configuration_cascades_but_history_blocks_deletion(): void
    {
        $clean = $this->target();
        $this->delete('/sources/'.$clean->tab->source_id)->assertRedirect();
        $this->assertDatabaseCount('column_mappings', 0);
        $target = $this->target();
        SyncRun::factory()->for($target, 'target')->create();
        $this->delete('/sources/'.$target->tab->source_id)->assertSessionHas('error');
        $this->delete('/tabs/'.$target->tab_id.'/targets/'.$target->id)->assertSessionHas('error');
        $this->assertDatabaseHas('column_mappings', ['target_id' => $target->id]);
    }

    public function test_google_discovery_adds_inactive_tabs_and_preserves_existing_settings(): void
    {
        $source = SheetSource::factory()->create();
        $existing = SheetTab::factory()->for($source, 'source')->create(['tab_name' => 'Sales', 'header_row' => 3, 'is_active' => true]);
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getSheetTitles')->once()->with($source->spreadsheet_id)->andReturn(['Sales', 'Summary']);
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->post('/sources/'.$source->id.'/discover')->assertRedirect();
        $this->assertSame(3, $existing->fresh()->header_row);
        $this->assertTrue($existing->fresh()->is_active);
        $this->assertDatabaseHas('sheet_tabs', ['source_id' => $source->id, 'tab_name' => 'Summary', 'is_active' => 0]);
    }

    public function test_header_suggestions_expose_ambiguity_without_failing_other_columns(): void
    {
        $tab = SheetTab::factory()->create(['header_row' => 3]);
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getHeaders')->once()->with($tab->source->spreadsheet_id, $tab->tab_name, 3)->andReturn(['name', 'total', 'total', null]);
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->postJson('/tabs/'.$tab->id.'/headers')->assertOk()->assertJsonPath('ambiguous', ['total'])->assertJsonPath('headers.0', 'name');
    }

    public function test_unknown_google_error_is_sanitized(): void
    {
        $source = SheetSource::factory()->create();
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getSheetTitles')->once()->andThrow(new \RuntimeException('PRIVATE_TOKEN_VALUE'));
        $this->app->instance(GoogleSheetsClient::class, $client);
        $response = $this->post('/sources/'.$source->id.'/check');
        $response->assertRedirect()->assertSessionHas('error');
        $this->assertStringNotContainsString('PRIVATE_TOKEN_VALUE', session('error'));
    }

    public function test_google_helpers_share_one_limit_across_users_files_and_actions(): void
    {
        $first = SheetSource::factory()->create();
        $second = SheetSource::factory()->create();
        $tab = SheetTab::factory()->for($second, 'source')->create();
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldReceive('getSheetTitles')->times(7)->andReturn(['Sales']);
        $client->shouldNotReceive('getHeaders');
        $this->app->instance(GoogleSheetsClient::class, $client);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/sources/'.$first->id.'/check')->assertRedirect();
        }
        $this->actingAs(User::factory()->create(['approved_at' => now()]));
        for ($i = 0; $i < 3; $i++) {
            $this->post('/sources/'.$second->id.'/discover')->assertRedirect();
        }
        $this->postJson('/tabs/'.$tab->id.'/headers')->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->post('/sources/'.$second->id.'/check')->assertRedirect();
    }

    public function test_options_reject_unknown_keys_deep_objects_and_excessive_bytes(): void
    {
        $tab = SheetTab::factory()->create();
        foreach (['{', '[]', '{"unexpected":1}', '{"timezone":{"a":{"b":{"c":1}}}}', '{"true_values":[["yes"]]}', json_encode(['timezone' => str_repeat('і', 6000)], JSON_UNESCAPED_UNICODE)] as $json) {
            $payload = $this->payload();
            $payload['mappings'][0]['type_options_json'] = $json;
            $this->post('/tabs/'.$tab->id.'/targets', $payload)->assertSessionHasErrors('mappings.0.type_options_json');
        }
        $this->assertDatabaseCount('sync_targets', 0);
        $payload = $this->payload();
        $payload['mappings'][0] = ['source_column' => 'enabled', 'target_column' => 'enabled', 'data_type' => 'boolean', 'type_options_json' => '{"true_values":["так","yes"],"false_values":["ні","no"]}'];
        $this->post('/tabs/'.$tab->id.'/targets', $payload)->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_target_explains_current_blocks_and_budget_after_mapping_change(): void
    {
        $target = $this->target();
        $fingerprint = app(ConfigurationFingerprint::class)->read(DB::connection(), $target->id);
        foreach (['configuration', 'row_validation', 'expired'] as $kind) {
            SyncRun::factory()->for($target, 'target')->create(['status' => 'failed', 'failure_kind' => $kind, 'config_fingerprint' => $fingerprint, 'finished_at' => now()]);
        }
        SyncRun::factory()->for($target, 'target')->create(['status' => 'failed', 'trigger_type' => 'manual', 'finished_at' => now()]);
        $url = '/tabs/'.$target->tab_id.'/targets/'.$target->id.'/edit';
        $this->get($url)->assertOk()->assertViewHas('scheduling', fn ($status) => $status['permanent_block'] && $status['scheduled_failures'] === 3)
            ->assertSee('Є постійний блок для поточної конфігурації')->assertSee('Вичерпано 3 спроби сьогодні')->assertSee('Запустити зараз');
        SyncRun::factory()->for($target, 'target')->create(['status' => 'succeeded', 'trigger_type' => 'manual', 'config_fingerprint' => $fingerprint, 'finished_at' => now()->addSecond()]);
        $this->get($url)->assertOk()->assertViewHas('scheduling', fn ($status) => ! $status['permanent_block'] && $status['scheduled_failures'] === 3);
        SyncRun::where('status', 'succeeded')->delete();
        $target->mappings()->update(['target_column' => 'changed']);
        $this->get($url)->assertOk()->assertViewHas('scheduling', fn ($status) => ! $status['permanent_block'] && $status['scheduled_failures'] === 3);
        $this->assertDatabaseCount('sync_runs', 4);
    }

    public function test_old_pending_warning_uses_activity_age_and_does_not_expire_runs(): void
    {
        $target = $this->target();
        $minutes = config('sheet-import.stale_after_minutes');
        SyncRun::factory()->for($target, 'target')->create(['created_at' => now()->subMinutes($minutes + 1)]);
        SyncRun::factory()->for($target, 'target')->create(['created_at' => now()->subHour(), 'heartbeat_at' => now()]);
        SyncRun::factory()->for($target, 'target')->create(['created_at' => now()->subHour(), 'status' => 'running']);
        foreach (['/runs', '/tabs/'.$target->tab_id.'/targets/'.$target->id.'/edit'] as $url) {
            $this->get($url)->assertOk()->assertViewHas('stalePendingCount', 1)->assertSee('Перевірте воркер')->assertSee((string) $minutes);
        }
        $this->assertDatabaseCount('sync_runs', 3);
        $this->assertSame(2, SyncRun::where('status', 'pending')->count());
    }

    private function payload(): array
    {
        return ['target_table' => 'ui_sales', 'is_active' => 1, 'mappings' => [['source_column' => " Product\u{00A0}  Name ", 'target_column' => 'name', 'data_type' => 'string', 'nullable' => 0, 'key' => 1, 'format' => null, 'type_options_json' => '{}']]];
    }

    private function target(): SyncTarget
    {
        $target = SyncTarget::factory()->create(['target_table' => 'ui_sales']);
        ColumnMapping::factory()->for($target, 'target')->create(['source_column' => 'name', 'target_column' => 'original', 'data_type' => 'string']);

        return $target;
    }
}
