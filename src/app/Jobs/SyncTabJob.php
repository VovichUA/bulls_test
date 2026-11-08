<?php

namespace App\Jobs;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunTriggerType;
use App\Models\SyncRun;
use App\Services\SheetImporter;
use App\Services\SyncDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncTabJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 180];

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public string $dispatchId, public int $tabId, public string $date, public string $trigger)
    {
        $this->onConnection('database');
        $this->onQueue('sheets');
    }

    public function handle(SyncDispatcher $dispatcher, SheetImporter $importer): void
    {
        $dispatcher->assertRuntimeConfiguration();
        $ids = $dispatcher->pending($this->dispatchId, $this->tabId, $this->date, SyncRunTriggerType::from($this->trigger));
        $runs = $importer->importPending($ids);
        if (($runs->contains(fn ($run) => $run->failure_kind === 'schema_lock_timeout' || $run->status === SyncRunStatus::Pending) || SyncRun::where('dispatch_id', $this->dispatchId)->whereIn('status', ['pending', 'running'])->exists()) && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 180);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(SyncDispatcher::class)->closeDispatch($this->dispatchId);
    }
}
