<?php

namespace App\Services\Importing;

use App\Models\SyncTarget;
use Illuminate\Database\Connection;

class SchedulingPolicy
{
    public function __construct(private readonly ConfigurationFingerprint $fingerprints) {}

    public function eligible(Connection $connection, SyncTarget $target, string $date, ?int $ignoreRunId = null): bool
    {
        $target = $target->fresh(['tab.source']);
        if (! $target || ! $target->is_active || ! $target->tab->is_active || ! $target->tab->source->is_active) {
            return false;
        }
        $scope = fn () => $connection->table('sync_runs')->useWritePdo()->where('target_id', $target->id)->where('snapshot_date', $date);
        if ($scope()->where('status', 'succeeded')->exists()
            || $scope()->whereIn('status', ['pending', 'running'])->when($ignoreRunId !== null, fn ($query) => $query->where('id', '!=', $ignoreRunId))->exists()
        ) {
            return false;
        }
        $status = $this->failureStatus($connection, $target->id, $date, $this->fingerprints->read($connection, $target->id));

        return ! $status['permanent_block'] && $status['scheduled_failures'] < $status['failure_limit'];
    }

    /** @return array{scheduled_failures: int, failure_limit: int, permanent_block: bool} */
    public function failureStatus(Connection $connection, int $targetId, string $date, string $fingerprint): array
    {
        $failures = $connection->table('sync_runs')->useWritePdo()->where('target_id', $targetId)->where('snapshot_date', $date)->where('trigger_type', 'scheduled')->where('status', 'failed')->count();
        $history = fn () => $connection->table('sync_runs')->useWritePdo()->where('target_id', $targetId)->where('config_fingerprint', $fingerprint);
        $failure = $history()->where('status', 'failed')->whereIn('failure_kind', ['configuration', 'api_permanent'])->orderByDesc('finished_at')->orderByDesc('id')->first();
        $recovered = $failure && $history()->where('status', 'succeeded')->where(function ($query) use ($failure) {
            $query->where('finished_at', '>', $failure->finished_at)->orWhere(function ($query) use ($failure) {
                $query->where('finished_at', $failure->finished_at)->where('id', '>', $failure->id);
            });
        })->exists();

        return ['scheduled_failures' => $failures, 'failure_limit' => 3, 'permanent_block' => $failure !== null && ! $recovered];
    }
}
