<?php

namespace App\Http\Controllers;

use App\Http\Requests\SheetTabRequest;
use App\Models\SheetSource;
use App\Models\SheetTab;
use App\Services\ConfigurationEditor;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\SheetRowMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class SheetTabController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['source' => ['nullable', 'integer', 'exists:sheet_sources,id']]);
        $tabs = SheetTab::with('source')->withCount('targets')->when($filters['source'] ?? null, fn ($query, $id) => $query->where('source_id', $id))->latest('id')->paginate(20)->withQueryString();

        return view('tabs.index', ['tabs' => $tabs, 'sources' => SheetSource::orderBy('name')->get()]);
    }

    public function create(SheetSource $source): View
    {
        return view('tabs.form', ['source' => $source, 'tab' => new SheetTab(['header_row' => 1, 'is_active' => false])]);
    }

    public function store(SheetTabRequest $request, SheetSource $source): RedirectResponse
    {
        $tab = $source->tabs()->create($request->validated());

        return redirect()->route('sources.tabs.show', [$source, $tab])->with('status', 'Вкладку додано.');
    }

    public function show(SheetSource $source, SheetTab $tab): View
    {
        $tab->load(['targets' => fn ($query) => $query->withCount('mappings')->orderBy('id')]);

        return view('tabs.show', compact('source', 'tab'));
    }

    public function edit(SheetSource $source, SheetTab $tab): View
    {
        return view('tabs.form', compact('source', 'tab'));
    }

    public function update(SheetTabRequest $request, SheetSource $source, SheetTab $tab): RedirectResponse
    {
        $tab->update($request->validated());

        return redirect()->route('sources.tabs.show', [$source, $tab])->with('status', 'Вкладку збережено.');
    }

    public function destroy(SheetSource $source, SheetTab $tab, ConfigurationEditor $editor): RedirectResponse
    {
        if (! $editor->delete($tab)) {
            return back()->with('error', 'Вкладка має історію запусків. Деактивуйте її замість видалення.');
        }

        return redirect()->route('sources.show', $source)->with('status', 'Конфігурацію вкладки видалено.');
    }

    public function headers(SheetTab $tab, GoogleSheetsClient $google, SheetRowMapper $mapper): JsonResponse
    {
        try {
            $headers = $google->getHeaders($tab->source->spreadsheet_id, $tab->tab_name, $tab->header_row);

            return response()->json(['headers' => $headers, 'ambiguous' => $mapper->ambiguous($headers)]);
        } catch (Throwable $exception) {
            return response()->json(['message' => $this->googleError($exception)], 422);
        }
    }
}
