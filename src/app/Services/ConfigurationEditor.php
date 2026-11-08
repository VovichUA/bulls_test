<?php

namespace App\Services;

use App\Http\Requests\SyncTargetRequest;
use App\Models\SheetSource;
use App\Models\SheetTab;
use App\Models\SyncTarget;
use App\Services\Importing\ConfigurationFingerprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfigurationEditor
{
    public function saveTarget(SheetTab $tab, SyncTargetRequest $request, ?SyncTarget $target = null): SyncTarget
    {
        return DB::transaction(function () use ($tab, $request, $target): SyncTarget {
            if ($target) {
                $target = SyncTarget::whereKey($target->id)->lockForUpdate()->firstOrFail();
                $fingerprint = app(ConfigurationFingerprint::class)->read(DB::connection(), $target->id);
                if (! hash_equals($fingerprint, $request->validated('config_fingerprint'))) {
                    throw ValidationException::withMessages(['config_fingerprint' => 'Конфігурація змінилася. Оновіть сторінку перед збереженням.']);
                }
                if ($target->target_table !== $request->validated('target_table') && $target->runs()->exists()) {
                    throw ValidationException::withMessages(['target_table' => 'Для target з історією створіть нову ціль замість перейменування таблиці.']);
                }
            } else {
                $target = new SyncTarget(['tab_id' => $tab->id]);
            }
            $target->fill(['target_table' => $request->validated('target_table'), 'is_active' => $request->boolean('is_active'), 'unique_keys' => $request->uniqueKeys()])->save();
            $target->mappings()->delete();
            $target->mappings()->createMany($request->mappingRecords());

            return $target;
        });
    }

    public function delete(Model $configuration): bool
    {
        $history = match (true) {
            $configuration instanceof SheetSource => $configuration->tabs()->whereHas('targets.runs')->exists(),
            $configuration instanceof SheetTab => $configuration->targets()->whereHas('runs')->exists(),
            $configuration instanceof SyncTarget => $configuration->runs()->exists(),
        };
        if ($history) {
            return false;
        }
        try {
            $configuration->delete();

            return true;
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                return false;
            }
            throw $exception;
        }
    }
}
