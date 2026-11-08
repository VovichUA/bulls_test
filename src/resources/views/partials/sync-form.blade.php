<div id="manual-import" class="card card-body">
    <h2 class="font-semibold">Ручний імпорт</h2><p class="mt-1 text-sm text-slate-500">{{ isset($tab) ? 'Ця вкладка та всі її активні цілі.' : 'Усі активні вкладки та цілі цього файлу.' }} Потрібен запущений worker.</p>
    <form method="POST" action="{{ route('sync.store') }}" class="mt-4 flex flex-wrap items-end gap-3">@csrf
        <input type="hidden" name="source" value="{{ $source->id }}">@isset($tab)<input type="hidden" name="tab" value="{{ $tab->id }}">@endisset
        <label class="field" for="snapshot-date">Дата знімка (Kyiv)<input class="input" id="snapshot-date" type="date" name="date" value="{{ old('date', today('Europe/Kyiv')->toDateString()) }}" max="{{ today('Europe/Kyiv')->toDateString() }}" min="1000-01-01" required></label>
        <button class="btn" @disabled(!$source->is_active || (isset($tab) && !$tab->is_active))>Запустити зараз</button>
    </form>
    <p class="hint">Для минулої дати читається поточний стан Google Sheets. Неактивну конфігурацію спочатку активуйте.</p>
</div>
