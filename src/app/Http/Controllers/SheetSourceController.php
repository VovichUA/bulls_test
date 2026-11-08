<?php

namespace App\Http\Controllers;

use App\Http\Requests\SheetSourceRequest;
use App\Models\SheetSource;
use App\Services\ConfigurationEditor;
use App\Services\GoogleSheets\GoogleSheetsClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class SheetSourceController extends Controller
{
    public function index(): View
    {
        return view('sources.index', ['sources' => SheetSource::withCount('tabs')->latest('id')->paginate(20)]);
    }

    public function create(): View
    {
        return view('sources.form', ['source' => new SheetSource(['is_active' => false])]);
    }

    public function store(SheetSourceRequest $request): RedirectResponse
    {
        $source = SheetSource::create($request->validated());

        return redirect()->route('sources.show', $source)->with('status', 'Файл додано. Налаштуйте вкладки та мапінг.');
    }

    public function show(SheetSource $source): View
    {
        $source->load(['tabs' => fn ($query) => $query->withCount('targets')->orderBy('id')]);

        return view('sources.show', compact('source'));
    }

    public function edit(SheetSource $source): View
    {
        return view('sources.form', compact('source'));
    }

    public function update(SheetSourceRequest $request, SheetSource $source): RedirectResponse
    {
        $source->update($request->validated());

        return redirect()->route('sources.show', $source)->with('status', 'Налаштування файлу збережено.');
    }

    public function destroy(SheetSource $source, ConfigurationEditor $editor): RedirectResponse
    {
        if (! $editor->delete($source)) {
            return back()->with('error', 'Файл має історію запусків. Деактивуйте його замість видалення.');
        }

        return redirect()->route('sources.index')->with('status', 'Конфігурацію файлу видалено.');
    }

    public function check(SheetSource $source, GoogleSheetsClient $google): RedirectResponse
    {
        try {
            $titles = $google->getSheetTitles($source->spreadsheet_id);

            return back()->with('status', 'Доступ підтверджено. Вкладок у файлі: '.count($titles).'.');
        } catch (Throwable $exception) {
            return back()->with('error', $this->googleError($exception));
        }
    }

    public function discover(SheetSource $source, GoogleSheetsClient $google): RedirectResponse
    {
        try {
            $titles = $google->getSheetTitles($source->spreadsheet_id);
            $added = DB::transaction(function () use ($source, $titles): int {
                $count = 0;
                foreach ($titles as $title) {
                    $tab = $source->tabs()->firstOrCreate(['tab_name' => $title], ['header_row' => 1, 'is_active' => false]);
                    $count += (int) $tab->wasRecentlyCreated;
                }

                return $count;
            });

            return redirect()->route('sources.show', $source)->with('status', 'Нових вкладок: '.$added.'. Вони неактивні до налаштування.');
        } catch (Throwable $exception) {
            return back()->with('error', $this->googleError($exception));
        }
    }
}
