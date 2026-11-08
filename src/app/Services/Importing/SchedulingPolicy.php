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
            || $scope()->where('trigger_type', 'scheduled')->where('status', 'failed')->count() >= 3) {
            return false;
        }
        $history = fn () => $connection->table('sync_runs')->useWritePdo()->where('target_id', $target->id)->where('config_fingerprint', $this->fingerprints->read($connection, $target->id));
        $failure = $history()->where('status', 'failed')->whereIn('failure_kind', ['configuration', 'api_permanent'])->orderByDesc('finished_at')->orderByDesc('id')->first();
        if (! $failure) {
            return true;
        }

        return $history()->where('status', 'succeeded')->where(function ($query) use ($failure) {
            $query->where('finished_at', '>', $failure->finished_at)->orWhere(function ($query) use ($failure) {
                $query->where('finished_at', $failure->finished_at)->where('id', '>', $failure->id);
            });
        })->exists();
    }
}
