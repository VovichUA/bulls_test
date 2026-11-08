<?php

namespace App\Http\Controllers;

use App\Enums\SyncRunTriggerType;
use App\Exceptions\SheetImportException;
use App\Services\SyncDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ManualSyncController extends Controller
{
    public function store(Request $request, SyncDispatcher $dispatcher): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['nullable', 'integer', 'exists:sheet_sources,id'],
            'tab' => ['nullable', 'integer', 'exists:sheet_tabs,id'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.today('Europe/Kyiv')->toDateString()],
        ]);
        try {
            $count = $dispatcher->enqueue(isset($data['source']) ? (int) $data['source'] : null, isset($data['tab']) ? (int) $data['tab'] : null, $data['date'] ?? null, SyncRunTriggerType::Manual);
            Log::info('Manual sheet imports queued from UI.', ['user_id' => $request->user()->id, 'tab_jobs' => $count]);

            return redirect()->route('runs.index', array_filter(['source' => $data['source'] ?? null, 'tab' => $data['tab'] ?? null]))
                ->with('status', $count > 0 ? 'У черзі вкладок: '.$count.'. Для обробки має працювати worker.' : 'Нових задач немає: перевірте активність конфігурації та наявні pending/running запуски.');
        } catch (SheetImportException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable) {
            return back()->with('error', 'Не вдалося поставити імпорт у чергу. Перевірте конфігурацію й повторіть спробу.');
        }
    }
}
