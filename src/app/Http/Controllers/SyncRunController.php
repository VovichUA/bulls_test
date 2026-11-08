<?php

namespace App\Http\Controllers;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunTriggerType;
use App\Models\SheetSource;
use App\Models\SheetTab;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SyncRunController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'source' => ['nullable', 'integer', 'exists:sheet_sources,id'],
            'tab' => ['nullable', 'integer', 'exists:sheet_tabs,id'],
            'target' => ['nullable', 'integer', 'exists:sync_targets,id'],
            'status' => ['nullable', Rule::enum(SyncRunStatus::class)],
            'trigger' => ['nullable', Rule::enum(SyncRunTriggerType::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $runs = SyncRun::with('target.tab.source')
            ->when($filters['source'] ?? null, fn ($query, $id) => $query->whereHas('target.tab', fn ($tab) => $tab->where('source_id', $id)))
            ->when($filters['tab'] ?? null, fn ($query, $id) => $query->whereHas('target', fn ($target) => $target->where('tab_id', $id)))
            ->when($filters['target'] ?? null, fn ($query, $id) => $query->where('target_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['trigger'] ?? null, fn ($query, $trigger) => $query->where('trigger_type', $trigger))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('snapshot_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('snapshot_date', '<=', $date))
            ->latest('id')->paginate(25)->withQueryString();

        return view('runs.index', ['stalePendingCount' => SyncRun::stalePending()->count(), 'staleMinutes' => config('sheet-import.stale_after_minutes'), 'runs' => $runs, 'sources' => SheetSource::orderBy('name')->get(), 'tabs' => SheetTab::with('source')->orderBy('tab_name')->get(), 'targets' => SyncTarget::with('tab.source')->orderBy('target_table')->get(), 'statuses' => SyncRunStatus::cases()]);
    }

    public function show(SyncRun $run): View
    {
        $run->load('target.tab.source');

        return view('runs.show', compact('run'));
    }
}
