<?php

namespace Tests\Feature;

use App\Enums\SyncRunTriggerType;
use App\Jobs\SyncTabJob;
use App\Services\SyncDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Mockery;
use Tests\TestCase;

class SchedulingConsoleTest extends TestCase
{
    public function test_manual_command_passes_source_and_tab_ids_to_dispatcher(): void
    {
        $dispatcher = Mockery::mock(SyncDispatcher::class);
        $dispatcher->shouldReceive('enqueue')->once()->with(2, 3, '2026-11-06', SyncRunTriggerType::Manual)->andReturn(1);
        $this->app->instance(SyncDispatcher::class, $dispatcher);
        $this->artisan('sheets:sync --source=2 --tab=3 --date=2026-11-06')->expectsOutputToContain('Queued 1 tab job(s).')->assertSuccessful();
    }

    public function test_scheduled_command_passes_scheduled_trigger(): void
    {
        $dispatcher = Mockery::mock(SyncDispatcher::class);
        $dispatcher->shouldReceive('enqueue')->once()->with(null, null, null, SyncRunTriggerType::Scheduled)->andReturn(0);
        $this->app->instance(SyncDispatcher::class, $dispatcher);
        $this->artisan('sheets:sync --scheduled')->expectsOutputToContain('Queued 0 tab job(s).')->assertSuccessful();
    }

    public function test_invalid_ids_do_not_reach_the_dispatcher(): void
    {
        $dispatcher = Mockery::mock(SyncDispatcher::class);
        $dispatcher->shouldNotReceive('enqueue');
        $this->app->instance(SyncDispatcher::class, $dispatcher);
        foreach (['--source=abc', '--tab=-1', '--source=0', '--tab=1.5'] as $option) {
            $this->artisan('sheets:sync '.$option)->assertFailed();
        }
    }

    public function test_schedule_is_daily_at_0200_in_kyiv_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'sheets:sync'));
        $this->assertCount(1, $events);
        $event = $events->sole();
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertSame('Europe/Kyiv', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--scheduled', $event->command);
    }

    public function test_schedule_uses_kyiv_0200_in_winter_and_summer_with_utc_application(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'sheets:sync'));
        foreach (['2026-01-15 00:00:00', '2026-07-14 23:00:00'] as $instant) {
            $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));
            $this->assertTrue($event->isDue($this->app));
            $this->travel(30)->minutes();
            $this->assertFalse($event->isDue($this->app));
        }
    }

    public function test_worker_limits_allow_session_termination_before_reservation_expiry(): void
    {
        $job = new SyncTabJob('id', 1, '2026-11-07', 'scheduled');
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 180], $job->backoff);
        $this->assertSame(600, $job->timeout);
        $retryAfter = config('queue.connections.database.retry_after');
        $this->assertGreaterThan($job->timeout, $retryAfter);
        $this->assertGreaterThan(615 + 15, $retryAfter);
        $this->assertLessThan(660, 615 + 15);
    }
}
