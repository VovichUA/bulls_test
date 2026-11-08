<?php

namespace Tests\Feature\Database;

use App\Jobs\SyncTabJob;
use App\Models\ColumnMapping;
use App\Models\SheetSource;
use App\Models\SheetTab;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use App\Models\User;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\Importing\ConfigurationFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\DdlDatabaseTestCase;

class AdminPanelTest extends DdlDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['approved_at' => now()]));
    }

    public function test_pages_render_without_automatic_google_calls(): void
    {
        $tab = SheetTab::factory()->create();
        $target = SyncTarget::factory()->for($tab, 'tab')->create();
        ColumnMapping::factory()->for($target, 'target')->create();
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldNotReceive('getSheetTitles');
        $client->shouldNotReceive('getHeaders');
        $this->app->instance(GoogleSheetsClient::class, $client);
        foreach (['/sources', '/sources/create', '/sources/'.$tab->source_id, '/sources/'.$tab->source_id.'/edit', '/tabs', '/sources/'.$tab->source_id.'/tabs/create', '/sources/'.$tab->source_id.'/tabs/'.$tab->id, '/sources/'.$tab->source_id.'/tabs/'.$tab->id.'/edit', '/targets', '/tabs/'.$tab->id.'/targets/create', '/tabs/'.$tab->id.'/targets/'.$target->id.'/edit', '/runs'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/sources')->assertSee('Додати файл');
    }

    public function test_mapping_edit_preserves_keys_type_options_and_date_format(): void
    {
        $target = SyncTarget::factory()->create(['unique_keys' => ['occurred_at']]);
        ColumnMapping::factory()->for($target, 'target')->create(['source_column' => 'when', 'target_column' => 'occurred_at', 'data_type' => 'datetime', 'format' => 'd.m.Y H:i:s', 'type_options' => ['timezone' => 'Europe/Kyiv']]);
        $this->get('/tabs/'.$target->tab_id.'/targets/'.$target->id.'/edit')->assertOk()->assertViewHas('rows', function ($rows) {
            return $rows[0]['key'] === true && $rows[0]['format'] === 'd.m.Y H:i:s' && json_decode($rows[0]['type_options_json'], true)['timezone'] === 'Europe/Kyiv';
        })->assertSee('Завантажити заголовки');
    }

    public function test_run_now_dispatches_only_the_selected_tab_as_manual(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create();
        SyncTarget::factory()->create();
        $date = today('Europe/Kyiv')->subDay()->toDateString();
        $this->post('/sync', ['source' => $target->tab->source_id, 'tab' => $target->tab_id, 'date' => $date])->assertRedirect();
        Queue::assertPushed(SyncTabJob::class, fn ($job) => $job->tabId === $target->tab_id && $job->date === $date && $job->trigger === 'manual');
        $this->assertDatabaseCount('sync_runs', 1);
        $this->assertSame($target->id, SyncRun::sole()->target_id);
    }

    public function test_mapping_form_and_fingerprint_share_one_snapshot_during_concurrent_edit(): void
    {
        $target = SyncTarget::factory()->create(['target_table' => 'ui_sales']);
        ColumnMapping::factory()->for($target, 'target')->create(['source_column' => 'name', 'target_column' => 'original', 'data_type' => 'string']);
        $fingerprint = app(ConfigurationFingerprint::class)->read(DB::connection(), $target->id);
        $other = DB::build(config('database.connections.mysql'));
        $changed = false;
        DB::listen(function ($query) use (&$changed, $other, $target): void {
            if (! $changed && str_starts_with($query->sql, 'select * from `column_mappings`')) {
                $changed = true;
                $other->transaction(function () use ($other, $target): void {
                    $other->table('sync_targets')->where('id', $target->id)->update(['target_table' => 'ui_changed']);
                    $other->table('column_mappings')->where('target_id', $target->id)->update(['target_column' => 'concurrent']);
                });
            }
        });
        try {
            $this->get('/tabs/'.$target->tab_id.'/targets/'.$target->id.'/edit')->assertOk()
                ->assertViewHas('target', fn ($shown) => $shown->target_table === 'ui_sales')
                ->assertViewHas('rows', fn ($rows) => $rows[0]['target_column'] === 'original')
                ->assertViewHas('fingerprint', $fingerprint);
            $this->assertTrue($changed);
            $this->put('/tabs/'.$target->tab_id.'/targets/'.$target->id, [
                'target_table' => 'ui_sales', 'config_fingerprint' => $fingerprint,
                'mappings' => [['source_column' => 'name', 'target_column' => 'original', 'data_type' => 'string']],
            ])->assertSessionHasErrors('config_fingerprint');
            $this->assertSame('ui_changed', $target->fresh()->target_table);
        } finally {
            $other->disconnect();
        }
    }

    public function test_future_date_and_mismatched_source_tab_do_not_enqueue(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create();
        $other = SheetSource::factory()->create();
        $this->post('/sync', ['date' => today('Europe/Kyiv')->addDay()->toDateString()])->assertSessionHasErrors('date');
        $this->post('/sync', ['source' => $other->id, 'tab' => $target->tab_id])->assertSessionHas('error');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('sync_runs', 0);
    }

    public function test_log_filters_and_error_rendering_are_scoped_and_escaped(): void
    {
        $first = SyncRun::factory()->create(['status' => 'failed', 'error' => '<script>alert(1)</script>']);
        $other = SyncRun::factory()->create(['status' => 'succeeded']);
        $this->get('/runs?source='.$first->target->tab->source_id.'&status=failed')->assertOk()->assertViewHas('runs', fn ($runs) => $runs->modelKeys() === [$first->id]);
        $this->get('/runs/'.$first->id)->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', escape: false);
        $this->get('/runs?status=unknown')->assertSessionHasErrors('status');
    }

    public function test_log_end_date_can_be_used_without_start_date(): void
    {
        SyncRun::factory()->create(['snapshot_date' => '2026-11-07']);
        $this->get('/runs?date_to=2026-11-07')->assertOk()->assertViewHas('runs', fn ($runs) => $runs->total() === 1);
    }

    public function test_run_list_is_paginated_and_retains_filters(): void
    {
        $target = SyncTarget::factory()->create();
        SyncRun::factory()->count(27)->for($target, 'target')->create(['status' => 'failed']);
        $this->get('/runs?target='.$target->id.'&status=failed')->assertOk()->assertViewHas('runs', fn ($runs) => $runs->count() === 25 && $runs->total() === 27)->assertSee('status=failed');
    }
}
