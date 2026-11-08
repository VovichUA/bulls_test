<x-app-layout>
    <x-slot name="header"><a class="link text-sm" href="{{ route('sources.show', $source) }}">← {{ $source->name }}</a><h1 class="page-title mt-2">{{ $tab->exists ? 'Налаштування вкладки' : 'Нова вкладка' }}</h1></x-slot>
    <div class="page"><form method="POST" action="{{ $tab->exists ? route('sources.tabs.update', [$source, $tab]) : route('sources.tabs.store', $source) }}" class="card card-body max-w-2xl space-y-6">@csrf @if($tab->exists)@method('PUT')@endif
        <label class="field" for="tab-name">Точна назва вкладки<input id="tab-name" class="input" name="tab_name" value="{{ old('tab_name', $tab->tab_name) }}" required maxlength="255"><span class="hint block">Назва має збігатися з назвою вкладки у Google Sheets.</span></label>
        <label class="field" for="header-row">Номер рядка заголовків<input id="header-row" class="input max-w-xs" name="header_row" type="number" min="1" max="1000000" value="{{ old('header_row', $tab->header_row) }}" required><span class="hint block">Рядки перед заголовками ігноруються; номери рядків джерела зберігаються абсолютними.</span></label>
        <input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $tab->is_active)) class="rounded border-slate-300 text-emerald-800">Активна вкладка</label>
        <div class="flex gap-3"><button class="btn">Зберегти</button><a class="btn-secondary" href="{{ route('sources.show', $source) }}">Скасувати</a></div>
    </form></div>
</x-app-layout>
