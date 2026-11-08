<?php

namespace App\Http\Controllers;

use App\Enums\ColumnDataType;
use App\Http\Requests\SyncTargetRequest;
use App\Models\SheetTab;
use App\Models\SyncRun;
use App\Models\SyncTarget;
use App\Services\ConfigurationEditor;
use App\Services\Importing\ConfigurationFingerprint;
use App\Services\Importing\SchedulingPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SyncTargetController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['tab' => ['nullable', 'integer', 'exists:sheet_tabs,id']]);
        $targets = SyncTarget::with('tab.source')->withCount('mappings')->when($filters['tab'] ?? null, fn ($query, $id) => $query->where('tab_id', $id))->latest('id')->paginate(20)->withQueryString();

        return view('targets.index', compact('targets'));
    }

    public function create(SheetTab $tab): View
    {
        return $this->form($tab, new SyncTarget(['is_active' => false]));
    }

    public function store(SyncTargetRequest $request, SheetTab $tab, ConfigurationEditor $editor): RedirectResponse
    {
        $target = $editor->saveTarget($tab, $request);

        return redirect()->route('tabs.targets.edit', [$tab, $target])->with('status', 'Ціль і мапінг збережено. Таблиця створиться під час імпорту.');
    }

    public function show(SheetTab $tab, SyncTarget $target): RedirectResponse
    {
        return redirect()->route('tabs.targets.edit', [$tab, $target]);
    }

    public function edit(SheetTab $tab, SyncTarget $target): View
    {
        return $this->form($tab, $target);
    }

    public function update(SyncTargetRequest $request, SheetTab $tab, SyncTarget $target, ConfigurationEditor $editor): RedirectResponse
    {
        $editor->saveTarget($tab, $request, $target);

        return redirect()->route('tabs.targets.edit', [$tab, $target])->with('status', 'Мапінг збережено. Існуючі колонки БД не видаляються.');
    }

    public function destroy(SheetTab $tab, SyncTarget $target, ConfigurationEditor $editor): RedirectResponse
    {
        if (! $editor->delete($target)) {
            return back()->with('error', 'Ціль має історію запусків. Деактивуйте її замість видалення.');
        }

        return redirect()->route('sources.tabs.show', [$tab->source_id, $tab])->with('status', 'Конфігурацію цілі видалено.');
    }

    private function form(SheetTab $tab, SyncTarget $target): View
    {
        return DB::transaction(function () use ($tab, $target): View {
            // MySQL's repeatable-read snapshot keeps rendered values and their token consistent.
            $tab = SheetTab::with('source')->findOrFail($tab->id);
            $target = $target->exists ? SyncTarget::findOrFail($target->id) : $target;
            $rows = $target->exists ? $target->mappings()->orderBy('id')->get()->map(fn ($mapping) => ['source_column' => $mapping->source_column, 'target_column' => $mapping->target_column, 'data_type' => $mapping->data_type->value, 'nullable' => $mapping->nullable, 'key' => in_array($mapping->target_column, $target->unique_keys ?? [], true), 'format' => $mapping->format ?? '', 'type_options_json' => json_encode($mapping->type_options ?? (object) [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])->all() : [];
            $fingerprint = $target->exists ? app(ConfigurationFingerprint::class)->read(DB::connection(), $target->id) : null;

            $scheduling = $target->exists ? app(SchedulingPolicy::class)->failureStatus(DB::connection(), $target->id, today('Europe/Kyiv')->toDateString(), $fingerprint) : null;
            $stalePendingCount = $target->exists ? SyncRun::stalePending()->where('target_id', $target->id)->count() : 0;

            return view('targets.form', ['scheduling' => $scheduling, 'stalePendingCount' => $stalePendingCount, 'staleMinutes' => config('sheet-import.stale_after_minutes'), 'tab' => $tab, 'target' => $target, 'rows' => old('mappings', $rows), 'fingerprint' => $fingerprint, 'types' => ColumnDataType::cases()]);
        });
    }
}
